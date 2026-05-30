<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';

require_login();
start_session();

$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$title = 'AI Chat';
$scriptVersion = @filemtime(__DIR__ . '/assets/ai-chat.js') ?: time();
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-1">AI Chat</h1>
    <p class="text-muted mb-0">Chat di intelligenza artificiale integrata con OpenAI Agent Builder.</p>
  </div>
</div>

<div class="card shadow-sm dojo-ai-chat-card">
  <div class="card-body p-0">
    <div id="aiChatError" class="alert alert-danger m-3 d-none" role="alert"></div>
    <openai-chatkit
      id="dojoAiChat"
      class="dojo-ai-chat"
      data-session-endpoint="<?= e($base) ?>/ai_chat_session.php"
      data-csrf="<?= e(csrf_token()) ?>"
    ></openai-chatkit>
  </div>
</div>

<script src="https://cdn.platform.openai.com/deployments/chatkit/chatkit.js" async></script>
<script src="<?= e($base) ?>/assets/ai-chat.js?v=<?= (int)$scriptVersion ?>" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
