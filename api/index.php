<?php
require_once __DIR__ . '/includes/api_log.php';
apiStartRequestLogging();
// index.php
echo "<h1># IBO API</h1>";
echo "<p>Sistema de gerenciamento de playlists e dispositivos</p>";
echo "<p>Acesse o <strong><a href='../admin/login.php'>Painel Admin</a></strong> ou a <strong><a href='../reseller/login.php'>Área da revenda</a></strong> para gerenciar os dispositivos.</p>";
echo "<p>Cadastre o MAC address com as credenciais (usuário/senha) e configure pelo menos um portal (servidor).</p>";
echo "<p>O dispositivo ira se registrar automaticamente na primeira conexao. Use a <code>Device Key</code> exibida no app para identificar o dispositivo no painel.</p>";
?>