<?php
/* Respuesta mínima para keep-alive / health check (Render, cron externo) */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 't' => time(), 'servicio' => 'Apsara Vimana']);
