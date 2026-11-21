<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Log;
use App\Metrics;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Card;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

// 1. Буферизация вывода и логирование каждого HTTP-запроса + тела ответа через Monolog
ob_start();
$rawRequestBody = file_get_contents('php://input') ?: '';

register_shutdown_function(function () use ($rawRequestBody): void {
    $responseBody = '';
    if (ob_get_level() > 0) {
        $responseBody = (string) ob_get_contents();
        ob_end_flush();
    }

    Log::info('HTTP request handled', [
        'method'        => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri'           => $_SERVER['REQUEST_URI'] ?? '/',
        'query'         => $_GET,
        'post'          => $_POST,
        'request_body'  => $rawRequestBody,
        'status_code'   => http_response_code(),
        'response_body' => $responseBody,
    ]);
});

// 2. Инициализация Eloquent ORM
Database::boot();

$dbError = null;
$board = null;
$columns = [];

try {
    // Гарантируем наличие тестового пользователя, доски и 3 колонок для канбана
    $user = User::firstOrCreate(
        ['email' => 'student@vyatsu.ru'],
        [
            'name'          => 'Студент ВятГУ',
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
        ]
    );

    $board = Board::firstOrCreate(
        ['title' => 'Учебная доска Trello'],
        [
            'description' => 'Канбан-доска проекта local-zoo',
            'owner_id'    => $user->id,
        ]
    );

    if ($board->columns()->count() === 0) {
        $defaultColumns = ['Нужно сделать', 'В работе', 'Готово'];
        foreach ($defaultColumns as $idx => $colTitle) {
            BoardColumn::create([
                'board_id' => $board->id,
                'title'    => $colTitle,
                'position' => $idx,
            ]);
        }
    }
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    Log::error('Failed to initialize Trello board', ['exception' => $dbError]);
}

// 3. Обработка POST-запросов (создание и перемещение карточек + сбор метрик)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $dbError === null) {
    $action = (string) ($_POST['action'] ?? 'create_card');

    if ($action === 'create_card') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $columnId = (int) ($_POST['column_id'] ?? 0);

        // Валидация + метрика бизнес-ошибок (которые не видны по HTTP 5xx)
        if ($title === '' || mb_strlen($title) > 200 || $columnId <= 0) {
            Metrics::businessError('create_card', 'validation');
            Log::error('Card validation failed', ['title' => $title, 'column_id' => $columnId]);
            http_response_code(422);
        } else {
            $start = microtime(true);
            try {
                $column = BoardColumn::find($columnId);
                if ($column === null) {
                    Metrics::businessError('create_card', 'column_not_found');
                    http_response_code(404);
                } else {
                    Card::create([
                        'column_id'   => $column->id,
                        'title'       => $title,
                        'description' => $description !== '' ? $description : null,
                        'position'    => (int) $column->cards()->count(),
                    ]);

                    Metrics::observeDbQuery('insert', microtime(true) - $start);
                    Metrics::cardCreated();
                    Metrics::noteCreated(); // сохраняем совместимость со старой метрикой

                    header('Location: /');
                    exit;
                }
            } catch (\Throwable $e) {
                Metrics::observeDbQuery('insert', microtime(true) - $start);
                Metrics::businessError('create_card', 'db_error');
                Log::error('Failed to create card', ['exception' => $e->getMessage()]);
                http_response_code(500);
            }
        }
    } elseif ($action === 'move_card') {
        $cardId = (int) ($_POST['card_id'] ?? 0);
        $targetColumnId = (int) ($_POST['target_column_id'] ?? 0);

        $start = microtime(true);
        try {
            $card = Card::find($cardId);
            $targetColumn = BoardColumn::find($targetColumnId);

            if ($card === null || $targetColumn === null) {
                Metrics::businessError('move_card', 'not_found');
                http_response_code(404);
            } else {
                $card->column_id = $targetColumn->id;
                $card->save();

                Metrics::observeDbQuery('update', microtime(true) - $start);
                header('Location: /');
                exit;
            }
        } catch (\Throwable $e) {
            Metrics::observeDbQuery('update', microtime(true) - $start);
            Metrics::businessError('move_card', 'db_error');
            Log::error('Failed to move card', ['exception' => $e->getMessage()]);
            http_response_code(500);
        }
    }
}

// 4. Чтение колонок и карточек через Eloquent с замером времени SELECT
$dbVersion = null;
if ($dbError === null && $board !== null) {
    $startSelect = microtime(true);
    try {
        $versionRow = Capsule::selectOne('SELECT version() AS version');
        $dbVersion = (string) ($versionRow->version ?? '');

        $columns = BoardColumn::with('cards')
            ->where('board_id', $board->id)
            ->orderBy('position')
            ->get();

        Metrics::observeDbQuery('select', microtime(true) - $startSelect);
    } catch (\Throwable $e) {
        Metrics::observeDbQuery('select', microtime(true) - $startSelect);
        Metrics::businessError('load_board', 'db_error');
        $dbError = $e->getMessage();
        Log::error('Failed to load board columns', ['exception' => $dbError]);
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($board?->title ?? 'Trello Board') ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 1040px; margin: 2rem auto; padding: 0 1rem; color: #1c1c1c; background: #f4f5f7; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .status-bar { font-size: 0.85rem; color: #555; margin-bottom: 1.5rem; }
        .ok { color: #0a7a2f; font-weight: 600; }
        .fail { color: #b00020; font-weight: 600; }
        .board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; align-items: start; }
        .column { background: #ebecf0; border-radius: 8px; padding: 0.8rem; }
        .column h2 { font-size: 1rem; margin: 0 0 0.8rem; display: flex; justify-content: space-between; }
        .card { background: #fff; border-radius: 6px; padding: 0.7rem; margin-bottom: 0.6rem; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .card-title { font-weight: 600; margin-bottom: 0.3rem; }
        .card-desc { font-size: 0.85rem; color: #555; margin-bottom: 0.5rem; }
        .move-form { display: flex; gap: 0.3rem; margin-top: 0.4rem; }
        .move-form select, .move-form button { font-size: 0.8rem; padding: 0.25rem 0.4rem; }
        .add-form { display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.8rem; border-top: 1px solid #d5d7dc; padding-top: 0.7rem; }
        .add-form input, .add-form textarea { padding: 0.45rem; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
        .add-form button { padding: 0.45rem; border: 0; border-radius: 4px; background: #2563eb; color: #fff; cursor: pointer; font-weight: 500; }
        .add-form button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <h1><?= h($board?->title ?? 'Trello Board') ?></h1>
    <div class="status-bar">
        PHP <?= h(PHP_VERSION) ?> | БД:
        <span class="<?= $dbError === null ? 'ok' : 'fail' ?>">
            <?= $dbError === null ? 'PostgreSQL подключена' : 'Ошибка: ' . h($dbError) ?>
        </span>
    </div>

    <?php if ($dbError === null): ?>
        <div class="board">
            <?php foreach ($columns as $column): ?>
                <div class="column">
                    <h2>
                        <span><?= h($column->title) ?></span>
                        <span>(<?= $column->cards->count() ?>)</span>
                    </h2>

                    <?php foreach ($column->cards as $card): ?>
                        <div class="card">
                            <div class="card-title"><?= h($card->title) ?></div>
                            <?php if ($card->description): ?>
                                <div class="card-desc"><?= h($card->description) ?></div>
                            <?php endif; ?>

                            <form class="move-form" method="post" action="/">
                                <input type="hidden" name="action" value="move_card">
                                <input type="hidden" name="card_id" value="<?= (int) $card->id ?>">
                                <select name="target_column_id">
                                    <?php foreach ($columns as $targetCol): ?>
                                        <option value="<?= (int) $targetCol->id ?>" <?= $targetCol->id === $column->id ? 'selected' : '' ?>>
                                            <?= h($targetCol->title) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit">Перенести</button>
                            </form>
                        </div>
                    <?php endforeach; ?>

                    <form class="add-form" method="post" action="/">
                        <input type="hidden" name="action" value="create_card">
                        <input type="hidden" name="column_id" value="<?= (int) $column->id ?>">
                        <input type="text" name="title" placeholder="Название карточки" maxlength="200">
                        <textarea name="description" rows="2" placeholder="Описание (необязательно)"></textarea>
                        <button type="submit">+ Добавить карточку</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</body>
</html>