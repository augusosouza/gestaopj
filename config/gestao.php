<?php

return [
    // Em homologação, aceitar NFS-e de produção restrita (tpAmb=2) na conferência.
    'aceitar_producao_restrita' => (bool) env('GESTAO_ACEITAR_PRODUCAO_RESTRITA', false),

    // Tamanho máximo do XML enviado, em KB.
    'xml_max_kb' => (int) env('GESTAO_XML_MAX_KB', 512),

    // Vencimento padrão do pagamento ao prestador: este dia do mês seguinte à competência.
    'dia_pagamento_prestadores' => (int) env('GESTAO_DIA_PAGAMENTO_PRESTADORES', 15),

    // 2FA obrigatório para administradores da empresa e revisores do escritório.
    'exigir_2fa' => (bool) env('GESTAO_EXIGIR_2FA', true),

    // Componentes publicados pela Receita Federal (código aberto, sem certificado digital).
    'receita' => [
        // Gerador de DANFSe: ReceitaFederal/calculadora-rtc-gerador-danfse (serviço interno).
        'danfse_url' => env('DANFSE_URL'), // ex.: http://danfse:8080 — vazio desliga o recurso
        'danfse_versao' => env('DANFSE_VERSAO', '1.0.0 (8d79e6b)'),
        'timeout' => (int) env('RECEITA_TIMEOUT', 20),
    ],
];
