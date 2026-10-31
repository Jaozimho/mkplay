<?php
session_start();
require_once __DIR__ . '/../api/includes/db.php';
$db = getDB();

// Verifica se já existe usuário
$stmt = $db->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() > 0) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Informe o usuário e a senha.";
    } elseif (strlen($password) < 6) {
        $error = "A senha deve ter pelo menos 6 caracteres.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, username, password, admin) VALUES (?, ?, ?, 1)");
        $stmt->execute(['Admin', $username, $hash]);
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = (int) $db->lastInsertId();
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Configuração Inicial</title>
    <style>
        body { font-family: Arial; margin: 50px; background: #f5f5f5; }
        .form { max-width: 400px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #4CAF50; color: white; padding: 10px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .error { color: red; margin: 10px 0; }
    </style>
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-page">
    <div class="form">
        <h2>Configuração Inicial</h2>
        <p>Defina o usuário administrador.</p>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="text" name="username" placeholder="Usuário" required>
            <input type="password" name="password" placeholder="Senha (mínimo 6 caracteres)" required>
            <button type="submit">Criar Usuário</button>
        </form>
    </div>
</body>
</html>