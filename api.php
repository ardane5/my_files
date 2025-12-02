<?php

/**
 * REST API для управления задачами
 */

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'api');

// Подключение к базе данных
try {
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  sendResponse(['error' => 'Ошибка подключения к БД: ' . $e->getMessage()], 500);
}

// Настройка заголовков
header('Content-Type: application/json; charset=utf-8');

// Получаем данные из POST запроса
$input = $_POST; // Форма
if (empty($input)) {
  $input = json_decode(file_get_contents('php://input'), true) ?: []; // JSON
}

// Определяем действие
$action = $input['action'] ?? '';

// Обработка действий
switch ($action) {
  case 'get_all':
    getAllTasks($pdo);
    break;
  case 'get_one':
    $id = $input['id'] ?? 0;
    getTask($pdo, $id);
    break;
  case 'create':
    createTask($pdo, $input);
    break;
  case 'update':
    $id = $input['id'] ?? 0;
    updateTask($pdo, $id, $input);
    break;
  case 'delete':
    $id = $input['id'] ?? 0;
    deleteTask($pdo, $id);
    break;
  default:
    sendResponse(['error' => 'Не указано действие или действие не поддерживается'], 400);
}

/**
 * Получить все задачи
 */
function getAllTasks($pdo)
{
  $stmt = $pdo->query("SELECT * FROM tasks ORDER BY created_at DESC");
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

  sendResponse(['success' => true, 'tasks' => $tasks]);
}

/**
 * Получить одну задачу
 */
function getTask($pdo, $id)
{
  if (!$id) {
    sendResponse(['error' => 'Не указан ID задачи'], 400);
  }

  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);
  $task = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$task) {
    sendResponse(['error' => 'Задача не найдена'], 404);
  }

  sendResponse(['success' => true, 'task' => $task]);
}

/**
 * Создать задачу
 */
function createTask($pdo, $data)
{
  // Валидация
  if (empty(trim($data['title'] ?? ''))) {
    sendResponse(['error' => 'Заголовок не может быть пустым'], 400);
  }

  $title = trim($data['title']);
  $description = trim($data['description'] ?? '');
  $status = in_array($data['status'] ?? '', ['pending', 'in_progress', 'completed'])
    ? $data['status']
    : 'pending';

  // Вставка в БД
  $stmt = $pdo->prepare("INSERT INTO tasks (title, description, status) VALUES (?, ?, ?)");
  $stmt->execute([$title, $description, $status]);

  $id = $pdo->lastInsertId();

  // Получаем созданную задачу
  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);
  $task = $stmt->fetch(PDO::FETCH_ASSOC);

  sendResponse([
    'success' => true,
    'message' => 'Задача создана',
    'task' => $task
  ], 201);
}

/**
 * Обновить задачу
 */
function updateTask($pdo, $id, $data)
{
  if (!$id) {
    sendResponse(['error' => 'Не указан ID задачи'], 400);
  }

  // Проверяем существование задачи
  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);

  if (!$stmt->fetch()) {
    sendResponse(['error' => 'Задача не найдена'], 404);
  }

  // Подготавливаем данные для обновления
  $updates = [];
  $params = [];

  if (isset($data['title'])) {
    $title = trim($data['title']);
    if (empty($title)) {
      sendResponse(['error' => 'Заголовок не может быть пустым'], 400);
    }
    $updates[] = "title = ?";
    $params[] = $title;
  }

  if (isset($data['description'])) {
    $updates[] = "description = ?";
    $params[] = trim($data['description']);
  }

  if (isset($data['status']) && in_array($data['status'], ['pending', 'in_progress', 'completed'])) {
    $updates[] = "status = ?";
    $params[] = $data['status'];
  }

  if (empty($updates)) {
    sendResponse(['error' => 'Нет данных для обновления'], 400);
  }

  // Добавляем ID в конец параметров
  $params[] = $id;

  // Выполняем обновление
  $sql = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  // Получаем обновленную задачу
  $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
  $stmt->execute([$id]);
  $task = $stmt->fetch(PDO::FETCH_ASSOC);

  sendResponse([
    'success' => true,
    'message' => 'Задача обновлена',
    'task' => $task
  ]);
}

/**
 * Удалить задачу
 */
function deleteTask($pdo, $id)
{
  if (!$id) {
    sendResponse(['error' => 'Не указан ID задачи'], 400);
  }

  // Проверяем существование
  $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ?");
  $stmt->execute([$id]);

  if (!$stmt->fetch()) {
    sendResponse(['error' => 'Задача не найдена'], 404);
  }

  $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
  $stmt->execute([$id]);

  sendResponse([
    'success' => true,
    'message' => 'Задача удалена'
  ]);
}

/**
 * Отправить JSON ответ
 */
function sendResponse($data, $statusCode = 200)
{
  http_response_code($statusCode);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

// Создание таблицы при первом обращении (если не существует)
function createTableIfNotExists($pdo)
{
  $sql = "CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";

  $pdo->exec($sql);
}

// Создаем таблицу при запуске
createTableIfNotExists($pdo);
