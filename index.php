<?php

session_start();

/**
 * 
 * Page variables
 * 
 */
define('TINYMCE_API_KEY', 'h2cqc7j42cmawkatv4jsapg531vyrncgjihwn1o6tqvgmvjz');

$page_url = explode('?', $_SERVER['REQUEST_URI'])[0];

$db = new SQLite3('db.sqlite');

$user_id = $_SESSION['user_id'] ?? null;

/**
 * 
 * Utility functions
 * 
 */

function generateCSRFToken()
{
  if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token)
{
  if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
    return true;
  }

  die('CSRF token validation failed');
}

function generateSlug($text)
{
  $slug = strtolower($text);
  $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
  $slug = trim($slug, '-');
  return $slug;
}

function generateBreadcrumbs($page_url, $db)
{
  $breadcrumbs = [['Home', '/']];

  if (str_starts_with($page_url, '/category/')) {
    $category_slug = str_replace('/category/', '', $page_url);
    $category_name = $db->querySingle("SELECT name FROM forum_categories WHERE slug = '$category_slug'");
    $breadcrumbs[] = [$category_name, $page_url];

  } elseif (str_starts_with($page_url, '/post/')) {
    $post_id = str_replace('/post/', '', $page_url);
    $post = $db->querySingle("SELECT title, category_id FROM forum_posts WHERE id = $post_id", true);
    $category = $db->querySingle("SELECT name, slug FROM forum_categories WHERE id = {$post['category_id']}", true);
    $breadcrumbs[] = [$category['name'], "/category/{$category['slug']}"];
    $breadcrumbs[] = [$post['title'], $page_url];

  } elseif ($page_url === '/create-categories') {
    $breadcrumbs[] = ['Create Category', $page_url];

  } elseif (str_starts_with($page_url, '/create-post')) {
    $category_slug = $_GET['category'];
    $category_name = $db->querySingle("SELECT name FROM forum_categories WHERE slug = '$category_slug'");
    $breadcrumbs[] = [$category_name, "/category/$category_slug"];
    $breadcrumbs[] = ['Create Post', $page_url];

  } elseif (str_starts_with($page_url, '/profile/')) {
    $username = str_replace('/profile/', '', $page_url);
    $breadcrumbs[] = [$username . "'s Profile", $page_url];
  }

  return $breadcrumbs;
}

// Add this function to hash passwords
function hashPassword($password)
{
  return password_hash($password, PASSWORD_DEFAULT);
}

// Add this function to verify passwords
function verifyPassword($password, $hash)
{
  return password_verify($password, $hash);
}

function sanitizeHTML($input)
{
  $search = array(
    '@<script[^>]*?>.*?</script>@si',   // Strip out javascript
    '@<[\/\!]*?[^<>]*?>@si',            // Strip out HTML tags
    '@<style[^>]*?>.*?</style>@siU',    // Strip style tags properly
    '@<![\s\S]*?--[ \t\n\r]*>@'         // Strip multi-line comments
  );
  $output = preg_replace($search, '', $input);
  return $output;
}

function renderHTML($input)
{
  $allowed_tags = '<p><br><strong><em><u><h1><h2><h3><h4><h5><h6><img><li><ol><ul><span><div><a>';
  $output = strip_tags(trim($input), $allowed_tags);
  return $output;
}

function dd($value)
{
  echo '<pre>';
  var_dump($value);
  echo '</pre>';
  die();
}

/**
 * 
 * Database setup
 * 
 */
if ($page_url === '/init') {
  // setup database tables
  // users
  $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, password TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

  // forum categories
  $db->exec("CREATE TABLE IF NOT EXISTS forum_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    name TEXT, 
    slug TEXT UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");

  // forum posts
  $db->exec("CREATE TABLE IF NOT EXISTS forum_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    title TEXT, 
    content TEXT, 
    user_id INTEGER, 
    category_id INTEGER, 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
    FOREIGN KEY (user_id) REFERENCES users(id), 
    FOREIGN KEY (category_id) REFERENCES forum_categories(id)
  )");

  // forum comments
  $db->exec("CREATE TABLE IF NOT EXISTS forum_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    content TEXT, 
    user_id INTEGER, 
    post_id INTEGER, 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
    FOREIGN KEY (user_id) REFERENCES users(id), 
    FOREIGN KEY (post_id) REFERENCES forum_posts(id)
  )");

  // Create users table if not exists
  $db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    email TEXT UNIQUE,
    password TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");

  // Create post_views table
  $db->exec("CREATE TABLE IF NOT EXISTS post_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    user_id INTEGER,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES forum_posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
  )");
}

/**
 * 
 * Routing
 * 
 */

if ($user_id && ($page_url === '/signup' || $page_url === '/login')) {
  header('Location: /');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'])) {
  switch ($page_url) {
    case '/create-categories':
      if (!$user_id) {
        header('Location: /login');
        exit;
      }
      $name = trim($_POST['name']);
      $errors = [];

      if (strlen($name) < 3) {
        $errors[] = "Category name must be at least 3 characters long.";
      }

      $slug = generateSlug($name);
      $existing_category = $db->querySingle("SELECT id FROM forum_categories WHERE slug = '$slug'");
      if ($existing_category) {
        $errors[] = "A category with this name already exists.";
      }

      if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = ['name' => $name];
        header('Location: /create-categories');
        exit;
      }

      $name = SQLite3::escapeString($name);
      $db->exec("INSERT INTO forum_categories (name, slug) VALUES ('$name', '$slug')");
      $_SESSION['success'] = "Category created successfully.";
      header('Location: /category/' . $slug);
      exit;
    case '/create-post':
      if (!$user_id) {
        header('Location: /login');
        exit;
      }
      $title = trim($_POST['title']);
      $content = trim($_POST['content']);
      $category_slug = SQLite3::escapeString($_GET['category']);

      $errors = [];
      if (strlen($title) < 10) {
        $errors[] = "Title must be at least 10 characters long.";
      }
      if (strlen($content) < 10) {
        $errors[] = "Content must be at least 10 characters long.";
      }

      if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = ['title' => $title, 'content' => $content];
        header('Location: /create-post?category=' . $category_slug);
        exit;
      }

      $title = SQLite3::escapeString($title);
      $content = SQLite3::escapeString($content);
      $category_id = (int) $db->querySingle("SELECT id FROM forum_categories WHERE slug = '$category_slug'");

      if ($category_id === 0) {
        die("Invalid category");
      }
      $db->exec("INSERT INTO forum_posts (title, content, user_id, category_id) VALUES ('$title', '$content', $user_id, $category_id)");
      $new_post_id = $db->lastInsertRowID();
      $_SESSION['success'] = "Post created successfully.";
      header('Location: /post/' . $new_post_id);
      exit;

    case '/create-comment':
      if (!$user_id) {
        header('Location: /login');
        exit;
      }
      $content = trim($_POST['content']);
      if (strlen($content) < 10) {
        $_SESSION['error'] = "Comment must be at least 10 characters long.";
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
      }
      $content = SQLite3::escapeString($content);
      $post_id = SQLite3::escapeString($_GET['post']);
      $user_id = $_SESSION['user_id'];
      $db->exec("INSERT INTO forum_comments (content, user_id, post_id) VALUES ('$content', '$user_id', '$post_id')");
      header('Location: /post/' . $post_id);
      exit;

    case '/signup':
      $username = trim($_POST['username']);
      $password = $_POST['password'];
      $confirm_password = $_POST['confirm_password'];

      $errors = [];
      if (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long.";
      }

      if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
      }
      if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
      }

      if (empty($errors)) {
        $username = SQLite3::escapeString($username);
        $hashed_password = hashPassword($password);

        try {
          $result = $db->exec("INSERT INTO users (username, password) VALUES ('$username', '$hashed_password')");

          if ($result) {
            $_SESSION['success'] = "Account created successfully. Please log in.";
            header('Location: /login');
            exit;
          } else {
            $errors[] = "Failed to create account. Please try again.";
            // Add error logging
            error_log("Failed to create account. SQLite error: " . $db->lastErrorMsg());
          }
        } catch (Exception $e) {
          $errors[] = "An error occurred. Please try again.";
        }
      }

      if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = ['username' => $username, 'email' => $email];
        header('Location: /signup');
        exit;
      }
      break;

    case '/login':
      $username = trim($_POST['username']);
      $password = $_POST['password'];

      $user = $db->querySingle("SELECT * FROM users WHERE username = '$username'", true);

      if ($user && verifyPassword($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['success'] = "Logged in successfully.";
        header('Location: /');
        exit;
      } else {
        $_SESSION['error'] = "Invalid username or password.";
        $_SESSION['form_data'] = ['username' => $username];
        header('Location: /login');
        exit;
      }
      break;
  }
}

// Add this to handle logout
if ($page_url === '/logout') {
  session_destroy();
  header('Location: /');
  exit;
}

/** 
 * 
 * Database access functions
 * 
 */

function getCategories()
{
  global $db;

  $categories = [];
  $query = "SELECT fc.id, fc.name, fc.slug, 
                   (SELECT COUNT(*) FROM forum_posts WHERE category_id = fc.id) as post_count,
                   (SELECT COUNT(*) FROM forum_comments WHERE post_id IN 
                     (SELECT id FROM forum_posts WHERE category_id = fc.id)) as comment_count,
                   (SELECT title FROM forum_posts WHERE category_id = fc.id ORDER BY created_at DESC LIMIT 1) as latest_post_title,
                   (SELECT username FROM users WHERE id = (SELECT user_id FROM forum_posts WHERE category_id = fc.id ORDER BY created_at DESC LIMIT 1)) as latest_post_author,
                   (SELECT id FROM forum_posts WHERE category_id = fc.id ORDER BY created_at DESC LIMIT 1) as latest_post_id,
                   (SELECT created_at FROM forum_posts WHERE category_id = fc.id ORDER BY created_at DESC LIMIT 1) as latest_post_date
            FROM forum_categories fc";

  $result = $db->query($query);

  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row;
  }
  return $categories;
}

function getPostsByCategory($category_slug)
{
  global $db;

  $posts = [];
  $category_slug = SQLite3::escapeString($category_slug);

  $category_id = $db->querySingle("SELECT id FROM forum_categories WHERE slug = '$category_slug'");
  if ($category_id === null) {
    error_log("Category not found for slug: " . $category_slug);
    return [];
  }

  $query = "SELECT p.id, p.title, p.content, p.created_at, u.username,
            (SELECT COUNT(*) FROM forum_comments WHERE post_id = p.id) as reply_count,
            (SELECT COUNT(*) FROM post_views WHERE post_id = p.id) as view_count,
            (SELECT MAX(c.created_at) FROM forum_comments c WHERE c.post_id = p.id) as last_reply_date
            FROM forum_posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.category_id = (SELECT id FROM forum_categories WHERE slug = '$category_slug')
            ORDER BY p.created_at DESC";

  $result = $db->query($query);

  if ($result === false) {
    return [];
  }

  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $posts[] = $row;
  }
  return $posts;
}


function getPostById($post_id)
{
  global $db;

  $query = "SELECT p.id, p.title, p.content, p.created_at, u.username, 
            (SELECT COUNT(*) FROM forum_posts WHERE user_id = p.user_id) +
            (SELECT COUNT(*) FROM forum_comments WHERE user_id = p.user_id) as author_post_count
            FROM forum_posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = :post_id";

  $stmt = $db->prepare($query);
  $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
  $result = $stmt->execute();

  return $result->fetchArray(SQLITE3_ASSOC);
}

function getCommentsByPostId($post_id)
{
  global $db;

  $query = "SELECT c.id, c.content, c.created_at, u.username,
            (SELECT COUNT(*) FROM forum_posts WHERE user_id = c.user_id) +
            (SELECT COUNT(*) FROM forum_comments WHERE user_id = c.user_id) as user_post_count
            FROM forum_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.post_id = :post_id
            ORDER BY c.created_at ASC";

  $stmt = $db->prepare($query);
  $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
  $result = $stmt->execute();

  $comments = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $comments[] = $row;
  }
  return $comments;
}

function getForumStats()
{
  global $db;

  $topic_count = $db->querySingle("SELECT COUNT(*) FROM forum_posts");
  $post_count = $db->querySingle("SELECT COUNT(*) FROM forum_posts") + $db->querySingle("SELECT COUNT(*) FROM forum_comments");
  $member_count = $db->querySingle("SELECT COUNT(*) FROM users");

  return [
    'topic_count' => $topic_count,
    'post_count' => $post_count,
    'member_count' => $member_count
  ];
}

function getCategoryStats($category_slug)
{
  global $db;

  $category_id = $db->querySingle("SELECT id FROM forum_categories WHERE slug = '$category_slug'");
  $topic_count = $db->querySingle("SELECT COUNT(*) FROM forum_posts WHERE category_id = $category_id");
  $post_count = $topic_count + $db->querySingle("SELECT COUNT(*) FROM forum_comments WHERE post_id IN (SELECT id FROM forum_posts WHERE category_id = $category_id)");

  return [
    'topic_count' => $topic_count,
    'post_count' => $post_count
  ];
}

function getCategoryBySlug($category_slug)
{
  global $db;

  $category_slug = SQLite3::escapeString($category_slug);
  $query = "SELECT id, name, slug FROM forum_categories WHERE slug = '$category_slug'";
  $result = $db->querySingle($query, true);

  return $result ?: null;
}

function getPostsByUserId($user_id)
{
  global $db;

  $query = "SELECT id, title, content, created_at FROM forum_posts WHERE user_id = $user_id";
  $result = $db->query($query);

  $posts = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $posts[] = $row;
  }

  return $posts;
}

function getCommentsByUserId($user_id)
{
  global $db;

  $query = "SELECT c.id, c.content, c.created_at, p.id AS post_id, p.title AS post_title 
            FROM forum_comments c
            JOIN forum_posts p ON c.post_id = p.id
            WHERE c.user_id = $user_id";
  $result = $db->query($query);

  $comments = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $comments[] = $row;
  }

  return $comments;
}

function getUserProfile($username)
{
  global $db;

  $username = SQLite3::escapeString($username);
  $query = "SELECT id, username, created_at FROM users WHERE username = '$username'";
  $result = $db->querySingle($query, true);

  if (!$result) {
    return null;
  }

  $user_id = $result['id'];
  $posts = getPostsByUserId($user_id);
  $comments = getCommentsByUserId($user_id);
  $post_count = count($posts);
  $comment_count = count($comments);



  return [
    'user' => $result,
    'posts' => $posts,
    'comments' => $comments,
    'post_count' => $post_count + $comment_count
  ];
}


/**
 * 
 * Markup start
 * 
 */

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forum</title>
  <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
  <script src="https://cdn.tiny.cloud/1/<?= TINYMCE_API_KEY ?>/tinymce/6/tinymce.min.js"
    referrerpolicy="origin"></script>
  <script>
    tinymce.init({
      selector: '.wysiwyg-editor',
      plugins: 'link lists',
      toolbar: 'undo redo | styles | bold italic underline | alignleft aligncenter alignright | bullist numlist | forecolor backcolor | fontsizeselect | link',
      menubar: false,
      statusbar: false,
      style_formats: [
        {
          title: 'Headings', items: [
            { title: 'Heading 1', format: 'h1' },
            { title: 'Heading 2', format: 'h2' },
            { title: 'Heading 3', format: 'h3' },
          ]
        },
      ],
      fontsize_formats: '8pt 10pt 12pt 14pt 18pt 24pt 36pt',
      content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; font-size: 14px; }',
    });
  </script>
</head>

<body>
  <header class="container mx-auto mt-8 px-4 max-w-4xl">
    <div class="flex justify-between items-center bg-gray-200 py-4 px-6 border border-gray-300 rounded-md shadow-sm">
      <a href="/" class="text-3xl font-bold text-blue-700">Forum</a>
      <nav class="space-x-4">
        <?php if (isset($_SESSION['user_id'])): ?>
          <span class="text-gray-600">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
          <a href="/logout" class="text-blue-600 hover:underline">Logout</a>
        <?php else: ?>
          <a href="/login" class="text-blue-600 hover:underline">Login</a>
          <a href="/signup" class="text-blue-600 hover:underline">Sign Up</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>
  <?php if ($page_url !== '/'): ?>
    <nav class="container mx-auto mt-4 px-4 max-w-4xl">
      <ol class="flex items-center text-sm p-2">
        <?php
        $breadcrumbs = generateBreadcrumbs($page_url, $db);
        foreach ($breadcrumbs as $index => $crumb):
          if ($index > 0)
            echo '<li class="mx-2 text-gray-500">&raquo;</li>';
          ?>
          <li>
            <?php if ($index < count($breadcrumbs) - 1): ?>
              <a href="<?php echo $crumb[1]; ?>" class="text-blue-600 hover:underline"><?php echo $crumb[0]; ?></a>
            <?php else: ?>
              <span class="font-bold text-gray-700"><?php echo $crumb[0]; ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </nav>
  <?php endif; ?>

  <?php if (isset($_SESSION['error'])): ?>
    <div class="max-w-4xl mx-auto mt-4 px-4">
      <p class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
        <?php
        echo $_SESSION['error'];
        unset($_SESSION['error']);
        ?>
      </p>
    </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['errors'])): ?>
    <div class="max-w-4xl mx-auto mt-4 px-4">
      <?php foreach ($_SESSION['errors'] as $error): ?>
        <p class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-2" role="alert">
          <?php echo $error; ?>
        </p>
      <?php endforeach; ?>
      <?php unset($_SESSION['errors']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="max-w-4xl mx-auto mt-4 px-4">
      <p class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
        <?php echo $_SESSION['success']; ?>
        <?php unset($_SESSION['success']); ?>
      </p>
    </div>
  <?php endif; ?>

  <?php if ($page_url === '/'): ?>
    <?php $forum_stats = getForumStats(); ?>
    <main class="max-w-4xl mx-auto m-4 px-4">
      <div class="bg-gray-200 p-4 rounded-lg">
        <header class="bg-blue-700 text-white p-4 rounded-t-lg flex justify-between items-center">
          <h1 class="text-2xl font-bold">Forum Categories</h1>
        </header>
        <div class="bg-white rounded-b-lg shadow-md">
          <div class="grid grid-cols-12 gap-4 p-3 bg-gray-200 font-semibold text-sm">
            <div class="col-span-6">Category</div>
            <div class="col-span-2 text-center">Topics</div>
            <div class="col-span-2 text-center">Posts</div>
            <div class="col-span-2 text-center">Last Post</div>
          </div>
          <?php $categories = getCategories();
          if (count($categories) === 0): ?>
            <div class="grid grid-cols-12 gap-4 p-3 text-sm items-center bg-gray-50">
              <div class="col-span-12 text-center py-4">
                <svg class="w-6 h-6 text-gray-400 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none"
                  viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
                <p class="text-gray-600">No categories found.</p>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($categories as $index => $category): ?>
              <div
                class="grid grid-cols-12 gap-4 p-3 text-sm items-center <?php echo $index % 2 === 0 ? 'bg-gray-50' : 'bg-white'; ?>">
                <div class="col-span-6 flex items-center">
                  <svg class="w-5 h-5 text-blue-600 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                    fill="currentColor">
                    <path fill-rule="evenodd"
                      d="M2 5a2 2 0 012-2h8a2 2 0 012 2v10a2 2 0 002 2H4a2 2 0 01-2-2V5zm3 1h6v4H5V6zm6 6H5v2h6v-2z"
                      clip-rule="evenodd" />
                    <path d="M15 7h1a2 2 0 012 2v5.5a1.5 1.5 0 01-3 0V7z" />
                  </svg>
                  <a href="/category/<?php echo $category['slug']; ?>" class="text-blue-600 hover:underline font-semibold">
                    <?php echo htmlspecialchars($category['name']); ?>
                  </a>
                </div>
                <div class="col-span-2 text-center"><?php echo $category['post_count']; ?></div>
                <div class="col-span-2 text-center"><?php echo $category['comment_count']; ?></div>
                <div class="col-span-2 text-center text-xs">
                  <?php if ($category['post_count'] > 0): ?>
                    <p class="font-semibold">
                      <a href="/post/<?php echo $category['latest_post_id']; ?>" class="text-blue-600 hover:underline">
                        <?php echo htmlspecialchars(substr($category['latest_post_title'], 0, 30)) . (strlen($category['latest_post_title']) > 30 ? '...' : ''); ?>
                      </a>
                    </p>
                    <p class="text-gray-500">by
                      <a href="/profile/<?php echo $category['latest_post_author']; ?>" class="text-blue-600 hover:underline">
                        <?php echo htmlspecialchars($category['latest_post_author']); ?>
                      </a>
                    </p>
                    <p class="text-gray-400"><?php echo date('M j, Y', strtotime($category['latest_post_date'])); ?></p>
                  <?php else: ?>
                    <p class="text-gray-500">No posts yet</p>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="mt-4 text-sm text-gray-600 flex items-center justify-between">
          <?php if (isset($_SESSION['user_id'])): ?>
            <a href="/create-categories"
              class="bg-blue-100 text-blue-700 px-3 py-1 rounded hover:bg-blue-200 flex items-center">
              New Category
              <svg class="w-4 h-4 ml-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                <polyline points="15 3 21 3 21 9"></polyline>
                <line x1="10" y1="14" x2="21" y2="3"></line>
              </svg>
            </a>
          <?php else: ?>
            <a href="/signup" class="text-blue-700 px-3 py-1 flex items-center">
              Sign Up to Create a Category
            </a>
          <?php endif; ?>
          <span class="self-end"><?php echo $forum_stats['topic_count']; ?> topics •
            <?php echo $forum_stats['post_count']; ?> posts • <?php echo $forum_stats['member_count']; ?> members</span>
        </div>
      </div>
    </main>
    <?php # Category Page ?>
  <?php elseif (str_starts_with($page_url, '/category')): ?>
    <?php
    $category_slug = str_replace('/category/', '', $page_url);
    $category = getCategoryBySlug($category_slug);
    if (!$category) {
      echo "<p>Category not found.</p>";
    } else {
      $posts = getPostsByCategory($category_slug);
      $category_stats = getCategoryStats($category_slug);
      ?>
      <main class="max-w-4xl mx-auto m-4 px-4">
        <div class="bg-gray-200 p-4 rounded-lg">
          <header class="bg-blue-700 text-white p-4 rounded-t-lg">
            <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($category['name']); ?></h1>
          </header>
          <div class="bg-white rounded-b-lg shadow-md">
            <div class="grid grid-cols-12 gap-4 p-3 bg-gray-200 font-semibold text-sm">
              <div class="col-span-6">Topic</div>
              <div class="col-span-2 text-center">Author</div>
              <div class="col-span-1 text-center">Replies</div>
              <div class="col-span-1 text-center">Views</div>
              <div class="col-span-2 text-center">Last Post</div>
            </div>
            <?php if (count($posts) === 0): ?>
              <div class="grid grid-cols-12 gap-4 p-3 text-sm items-center bg-gray-50">
                <div class="col-span-12 text-center py-4">
                  <svg class="w-6 h-6 text-gray-400 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                  </svg>
                  <p class="text-gray-600">No posts found in this category.</p>
                </div>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($posts as $index => $post): ?>
              <div
                class="grid grid-cols-12 gap-4 p-3 text-sm items-center <?php echo $index % 2 === 0 ? 'bg-gray-50' : 'bg-white'; ?>">
                <div class="col-span-6 flex items-center">
                  <svg class="w-5 h-5 text-blue-600 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                  </svg>
                  <a href="/post/<?php echo $post['id']; ?>"
                    class="text-blue-600 hover:underline"><?php echo htmlspecialchars($post['title']); ?></a>
                </div>
                <div class="col-span-2 text-center flex items-center justify-center">
                  <svg class="w-4 h-4 mr-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                  </svg>
                  <a href="/profile/<?php echo $post['username']; ?>"
                    class="text-blue-600 hover:underline"><?php echo htmlspecialchars($post['username']); ?></a>
                </div>
                <div class="col-span-1 text-center"><?php echo $post['reply_count']; ?></div>
                <div class="col-span-1 text-center"><?php echo $post['view_count']; ?></div>
                <div class="col-span-2 text-center">
                  <?php if ($post['last_reply_date']): ?>
                    <?php echo date('M j, Y', strtotime($post['last_reply_date'])); ?>
                  <?php else: ?>
                    No replies
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="mt-4 text-sm text-gray-600 flex items-center justify-between">
          <?php if (isset($_SESSION['user_id'])): ?>
            <a href="/create-post?category=<?php echo $category['slug']; ?>"
              class="bg-blue-100 text-blue-700 px-3 py-1 rounded hover:bg-blue-200 flex items-center">
              New Topic
              <svg class="w-4 h-4 ml-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                <polyline points="15 3 21 3 21 9"></polyline>
                <line x1="10" y1="14" x2="21" y2="3"></line>
              </svg>
            </a>
          <?php else: ?>
            <a href="/signup" class="text-blue-700 px-3 py-1 flex items-center">
              Sign Up to Create a Topic
            </a>
          <?php endif; ?>
          <span class="self-end"><?php echo $category_stats['topic_count']; ?> topics •
            <?php echo $category_stats['post_count']; ?> posts</span>
        </div>
        </div>
      </main>
      <?php
    }
    ?>
  <?php elseif (str_starts_with($page_url, '/post')): ?>
    <?php
    $post_id = str_replace('/post/', '', $page_url);
    $post = getPostById($post_id);
    $comments = getCommentsByPostId($post_id);

    // Increment view count
    if ($user_id) {
      $db->exec("INSERT INTO post_views (post_id, user_id) VALUES ($post_id, $user_id)");
    } else {
      $db->exec("INSERT INTO post_views (post_id) VALUES ($post_id)");
    }
    ?>
    <main class="max-w-4xl mx-auto m-4 px-4">
      <div class="bg-gray-200 p-4 rounded-lg">
        <header class="bg-blue-700 text-white p-4 rounded-t-lg">
          <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($post['title']); ?></h1>
        </header>
        <div class="bg-white border border-gray-300 mb-4">

          <div class="p-4 flex">
            <div class="w-32 text-center">
              <svg class="w-16 h-16 mx-auto text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <a href="/profile/<?php echo $post['username']; ?>"
                class="mt-2 text-sm font-bold text-blue-600 hover:underline"><?php echo htmlspecialchars($post['username']); ?></a>
              <p class="text-xs text-gray-500">Posts: <?php echo $post['author_post_count']; ?></p>
            </div>
            <div class="flex-1 ml-4">
              <div class="mb-4 prose"><?php echo renderHTML($post['content']); ?></div>
              <p class="mt-4 text-xs text-gray-500">Posted on:
                <?php echo date('F j, Y', strtotime($post['created_at'])); ?>
              </p>
            </div>
          </div>
        </div>

        <!-- Replies -->
        <?php foreach ($comments as $comment): ?>
          <div class="bg-white border border-gray-300 mb-4" id="comment<?php echo $comment['id']; ?>">
            <div class="bg-gray-100 p-2 border-b border-gray-300">
              <h3 class="font-bold">Re: <?php echo htmlspecialchars($post['title']); ?></h3>
            </div>
            <div class="p-4 flex">
              <div class="w-32 text-center">
                <svg class="w-16 h-16 mx-auto text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                  viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <a href="/profile/<?php echo $comment['username']; ?>"
                  class="mt-2 text-sm font-bold text-blue-600 hover:underline"><?php echo htmlspecialchars($comment['username']); ?></a>
                <p class="text-xs text-gray-500">Posts: <?php echo $comment['user_post_count']; ?></p>
              </div>
              <div class="flex-1 ml-4">
                <div class="mb-4 prose"><?php echo renderHTML($comment['content']); ?></div>
                <p class="mt-4 text-xs text-gray-500">Posted on:
                  <?php echo date('F j, Y', strtotime($comment['created_at'])); ?>
                </p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Reply Form -->
        <div class="bg-white border border-gray-300 p-4">
          <?php if ($user_id): ?>
            <h3 class="font-bold mb-2">Post a Reply</h3>
            <form action="/create-comment?post=<?php echo $post['id']; ?>" method="post">
              <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
              <textarea name="content" class="wysiwyg-editor w-full p-2 border border-gray-300 rounded" rows="4"
                placeholder="Type your reply here..."></textarea>
              <button type="submit" class="mt-2 bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                <svg class="inline-block w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                  stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                </svg>
                Post Reply
              </button>
            </form>
          <?php else: ?>
            <a href="/login" class="text-blue-700 px-3 py-1 flex items-center">
              Sign In to Post a Reply
            </a>
          <?php endif; ?>
        </div>
    </main>
  <?php elseif (str_starts_with($page_url, '/profile')): ?>
    <?php
    $username = str_replace('/profile/', '', $page_url);
    $profile = getUserProfile($username);
    if (!$profile) {
      echo "<p>Profile not found.</p>";
    } else {
      ?>
      <main class="max-w-4xl mx-auto m-4 px-4">
        <div class="bg-gray-200 p-4 rounded-lg">
          <header class="bg-blue-700 text-white p-4 rounded-t-lg">
            <h1 class="text-2xl font-bold">Profile</h1>
          </header>
          <div class="bg-white rounded-b-lg shadow-md p-6">
            <div class="flex items-center">
              <div class="w-16 h-16 rounded-full mr-4">
                <svg class="w-16 h-16 mx-auto text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                  viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </div>
              <div>
                <h2 class="text-lg font-bold"><?php echo htmlspecialchars($profile['user']['username']); ?></h2>
                <p class="text-sm text-gray-500">Member since
                  <?php echo date('F Y', strtotime($profile['user']['created_at'])); ?>
                </p>
                <p class="text-sm text-gray-500">
                  <?php echo $profile['post_count']; ?> posts
                </p>
              </div>
            </div>
          </div>

          <div class="mt-4 bg-white rounded-b-lg shadow-md p-6">

            <h3 class="text-lg font-bold">Posts</h3>
            <ul class="list-disc pl-4">
              <?php
              $combined = [];
              $posts = $profile['posts'];
              $comments = $profile['comments'];

              foreach ($posts as $post) {
                $combined[] = [
                  'content' => $post['content'],
                  'created_at' => $post['created_at'],
                  'post_id' => $post['id'],
                  'post_title' => $post['title'],
                ];
              }

              foreach ($comments as $comment) {
                $combined[] = [
                  'content' => $comment['content'],
                  'created_at' => $comment['created_at'],
                  'post_id' => $comment['post_id'],
                  'post_title' => $comment['post_title'],
                  'comment_id' => $comment['id'],
                ];
              }

              usort($combined, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
              });


              foreach ($combined as $post): ?>
                <?php
                $suffix = isset($post['comment_id']) ? '#comment' . $post['comment_id'] : '';
                ?>
                <li>
                  <a href="/post/<?php echo $post['post_id'] . $suffix; ?>" class="text-blue-600 hover:underline">
                    <?= isset($post['comment_id']) ? 'Re: ' : '' ?>
                    <?php echo renderHTML($post['post_title']); ?>
                  </a>

                  <p class="text-sm text-gray-500">
                    <?php echo substr(renderHTML($post['content']), 0, 100) . '...'; ?>
                  </p>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

        </div>
      </main>
      <?php
    }
    ?>
  <?php elseif ($page_url === '/create-categories'): ?>
    <?php
    $form_data = $_SESSION['form_data'] ?? ['name' => ''];
    unset($_SESSION['form_data']);
    ?>
    <main class="max-w-4xl mx-auto m-4 px-4">
      <div class="bg-gray-200 p-4 rounded-lg">
        <header class="bg-blue-700 text-white p-4 rounded-t-lg">
          <h1 class="text-2xl font-bold">Create New Category</h1>
        </header>
        <div class="bg-white rounded-b-lg shadow-md p-6">
          <form action="/create-categories" method="post" class="">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="mb-4">
              <label for="name" class="block text-sm font-medium text-gray-700">Category Name</label>
              <input type="text" id="name" name="name" required placeholder="Category Name (minimum 3 characters)"
                value="<?php echo htmlspecialchars($form_data['name']); ?>"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1">
            </div>
            <div>
              <button type="submit"
                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Create Category
              </button>
            </div>
          </form>
        </div>
      </div>
    </main>
  <?php elseif (str_starts_with($page_url, '/create-post')): ?>
    <?php
    $category_slug = $_GET['category'];
    $form_data = $_SESSION['form_data'] ?? ['title' => '', 'content' => ''];
    unset($_SESSION['form_data']);
    ?>
    <main class="max-w-4xl mx-auto m-4 px-4">
      <div class="bg-gray-200 p-4 rounded-lg">
        <header class="bg-blue-700 text-white p-4 rounded-t-lg">
          <h1 class="text-2xl font-bold">Create New Post</h1>
        </header>
        <div class="bg-white rounded-b-lg shadow-md p-6">
          <form action="/create-post?category=<?php echo $category_slug; ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="mb-4">
              <label for="title" class="block text-sm font-medium text-gray-700">Post Title</label>
              <input type="text" id="title" name="title" required placeholder="Post Title (minimum 10 characters)"
                value="<?php echo htmlspecialchars($form_data['title']); ?>"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1">
            </div>
            <div class="mb-4">
              <label for="content" class="block text-sm font-medium text-gray-700">Post Content</label>
              <textarea id="content" name="content" class="wysiwyg-editor" rows="6"
                placeholder="Post Content (minimum 10 characters)"><?php echo htmlspecialchars($form_data['content']); ?></textarea>
            </div>
            <div>
              <button type="submit"
                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Create Post
              </button>
            </div>
          </form>
        </div>
      </div>
    </main>
  <?php elseif ($page_url === '/signup'): ?>
    <?php
    $form_data = $_SESSION['form_data'] ?? ['username' => '', 'email' => ''];
    unset($_SESSION['form_data']);
    ?>
    <main class="max-w-4xl mx-auto m-4 px-4">
      <div class="bg-gray-200 p-4 rounded-lg">
        <header class="bg-blue-700 text-white p-4 rounded-t-lg">
          <h1 class="text-2xl font-bold">Sign Up</h1>
        </header>
        <div class="bg-white rounded-b-lg shadow-md p-6">
          <form action="/signup" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div>
              <label for="content" class="block text-sm font-medium text-gray-700">Username</label>
              <input type="text" id="username" name="username" required
                value="<?php echo htmlspecialchars($form_data['username']); ?>"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1"
                placeholder="Username">
            </div>
            <div>

              <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
              <input type="password" id="password" name="password" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1"
                placeholder="Password">
            </div>
            <div>

              <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
              <input type="password" id="confirm_password" name="confirm_password" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1"
                placeholder="Confirm Password">
            </div>
            <div>
              <button type="submit"
                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Sign Up
              </button>
            </div>
            <div>
              <a href="/login" class="text-blue-700 flex items-center text-sm">
                Already have an account? Login
              </a>
            </div>
          </form>
        </div>
      </div>
    </main>

  <?php elseif ($page_url === '/login'): ?>
    <?php
    $form_data = $_SESSION['form_data'] ?? ['username' => ''];
    unset($_SESSION['form_data']);
    ?>
    <main class="max-w-4xl mx-auto m-4 px-4">
      <div class="bg-gray-200 p-4 rounded-lg">
        <header class="bg-blue-700 text-white p-4 rounded-t-lg">
          <h1 class="text-2xl font-bold">Login</h1>
        </header>
        <div class="bg-white rounded-b-lg shadow-md p-6">
          <form action="/login" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div>
              <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
              <input type="text" id="username" name="username" required
                value="<?php echo htmlspecialchars($form_data['username']); ?>"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1"
                placeholder="Username">
            </div>
            <div>
              <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
              <input type="password" id="password" name="password" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mt-1"
                placeholder="Password">
            </div>
            <div>
              <button type="submit"
                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Login
              </button>
            </div>
            <div>
              <a href="/signup" class="text-blue-700 flex items-center text-sm">
                Don't have an account? Sign Up
              </a>
            </div>
          </form>
        </div>
      </div>
    </main>

  <?php endif; ?>
</body>

</html>