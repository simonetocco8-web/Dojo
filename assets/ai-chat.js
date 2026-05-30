(function () {
  'use strict';

  function showError(message) {
    var box = document.getElementById('aiChatError');
    if (!box) return;
    box.textContent = message;
    box.classList.remove('d-none');
  }

  async function setupChat() {
    var chat = document.getElementById('dojoAiChat');
    if (!chat) return;

    var endpoint = chat.dataset.sessionEndpoint || 'ai_chat_session.php';
    var csrf = chat.dataset.csrf || '';
    var domainKey = chat.dataset.domainKey || '';

    if (window.customElements && !customElements.get('openai-chatkit')) {
      await Promise.race([
        customElements.whenDefined('openai-chatkit'),
        new Promise(function (_, reject) {
          setTimeout(function () {
            reject(new Error('Chat AI non disponibile. Verifica la connessione allo script OpenAI.'));
          }, 10000);
        }),
      ]);
    }

    if (typeof chat.setOptions !== 'function') {
      showError('Chat AI non disponibile. Ricarica la pagina o verifica la connessione allo script OpenAI.');
      return;
    }

    chat.setOptions({
      api: {
        domainKey: domainKey,
        async getClientSecret(currentClientSecret) {
          var response = await fetch(endpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf,
            },
            body: JSON.stringify({ current_client_secret: currentClientSecret || null }),
            credentials: 'same-origin',
          });

          var data = await response.json().catch(function () { return {}; });
          if (!response.ok || !data.client_secret) {
            throw new Error(data.error || 'Impossibile avviare la sessione AI Chat.');
          }
          return data.client_secret;
        },
      },
      locale: 'it-IT',
      theme: 'light',
      frameTitle: 'AI Chat Dojo',
      header: {
        title: {
          enabled: true,
          text: 'AI Chat',
        },
      },
      startScreen: {
        greeting: 'Ciao, come posso aiutarti?',
      },
      composer: {
        placeholder: 'Scrivi un messaggio...',
      },
    });

    chat.addEventListener('chatkit.error', function (event) {
      var error = event.detail && event.detail.error;
      showError(error && error.message ? error.message : 'Errore nella chat AI.');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupChat().catch(function (error) {
      showError(error && error.message ? error.message : 'Errore durante il caricamento della chat AI.');
    });
  });
}());
