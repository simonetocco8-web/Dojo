<?php
// core/token.php
function random_hex(int $len): string { return bin2hex(random_bytes($len)); }
function token_pair(): array {
  $selector = random_hex(8);
  $validator = random_bytes(32);
  $validator_hex = bin2hex($validator);
  $validator_hash = hash('sha256', $validator);
  return [$selector, $validator_hex, $validator_hash];
}
