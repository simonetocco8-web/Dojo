<?php
return [
  'openai_chatkit' => [
    // Copia questo file in config/env.local.php e inserisci qui la chiave reale.
    // config/env.local.php è ignorato da Git e non deve essere committato.
    'api_key' => 'INSERISCI_LA_TUA_OPENAI_API_KEY',
    // Opzionale: se vuoi bloccare una versione deployata specifica del workflow.
    // Lascia vuoto per usare l'ultima versione pubblicata/deployata.
    'workflow_version' => '',
  ],
];
