<?php

return [
    // Em homologação, aceitar NFS-e de produção restrita (tpAmb=2) na conferência.
    'aceitar_producao_restrita' => (bool) env('GESTAO_ACEITAR_PRODUCAO_RESTRITA', false),

    // Tamanho máximo do XML enviado, em KB.
    'xml_max_kb' => (int) env('GESTAO_XML_MAX_KB', 512),

    // 2FA obrigatório para administradores da empresa e revisores do escritório.
    'exigir_2fa' => (bool) env('GESTAO_EXIGIR_2FA', true),
];
