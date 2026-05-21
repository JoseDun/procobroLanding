<?php

return [
    // SMTP
    'smtp_host' => 'mail.tudominio.cl',
    'smtp_port' => 465,
    'smtp_user' => 'contacto@tudominio.cl',
    'smtp_pass' => 'CAMBIA_ESTA_CLAVE',
    // Usa "ssl" para puerto 465, "tls" para puerto 587, o "none" si tu proveedor lo pide.
    'smtp_secure' => 'ssl',

    // Remitente y destinatario
    'from_email' => 'contacto@tudominio.cl',
    'from_name' => 'Procobro',
    'to_email' => 'contacto@procobro.cl',
    'to_name' => 'Procobro',

    // Asunto
    'subject_prefix' => 'Nuevo contacto desde Procobro',
];
