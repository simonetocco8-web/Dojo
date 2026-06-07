(function () {
  'use strict';

  function byId(id) {
    return document.getElementById(id);
  }

  function showError(message) {
    var box = byId('aiChatError');
    if (!box) return;
    box.textContent = message;
    box.classList.remove('d-none');
  }

  function hideError() {
    var box = byId('aiChatError');
    if (!box) return;
    box.classList.add('d-none');
    box.textContent = '';
  }

  function appendMessage(role, text) {
    var messages = byId('aiChatMessages');
    if (!messages) return;

    var wrapper = document.createElement('div');
    wrapper.className = role === 'user' ? 'd-flex justify-content-end mb-3' : 'd-flex justify-content-start mb-3';

    var bubble = document.createElement('div');
    bubble.className = role === 'user' ? 'alert alert-primary mb-0 py-2 px-3' : 'alert alert-light border mb-0 py-2 px-3';
    bubble.style.maxWidth = '82%';
    bubble.style.whiteSpace = 'pre-wrap';
    bubble.textContent = text;

    wrapper.appendChild(bubble);
    messages.appendChild(wrapper);
    messages.scrollTop = messages.scrollHeight;
  }

  function setLoading(isLoading) {
    var button = byId('aiChatSubmit');
    var input = byId('aiChatInput');
    if (button) {
      button.disabled = isLoading;
      button.innerHTML = isLoading
        ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Invio...'
        : 'Invia';
    }
    if (input) input.disabled = isLoading;
  }

  function setupChat() {
    var form = byId('aiChatForm');
    var input = byId('aiChatInput');
    var endpointHolder = byId('dojoAiChat');
    if (!form || !input || !endpointHolder) return;

    var endpoint = endpointHolder.dataset.sessionEndpoint || 'ai_chat_session.php';
    var csrf = endpointHolder.dataset.csrf || '';
    var previousResponseId = null;

    appendMessage('assistant', 'Ciao, come posso aiutarti?');

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var message = input.value.trim();
      if (!message) return;

      hideError();
      appendMessage('user', message);
      input.value = '';
      setLoading(true);

      try {
        var response = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: JSON.stringify({
            message: message,
            previous_response_id: previousResponseId,
          }),
          credentials: 'same-origin',
        });

        var data = await response.json().catch(function () { return {}; });
        if (!response.ok || !data.message) {
          throw new Error(data.error || 'Impossibile ottenere una risposta dalla chat AI.');
        }

        previousResponseId = data.response_id || previousResponseId;
        appendMessage('assistant', data.message);
      } catch (error) {
        showError(error && error.message ? error.message : 'Errore nella chat AI.');
      } finally {
        setLoading(false);
        input.focus();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', setupChat);
}());
