<?php

return [
    'after_or_equal' => 'O campo :attribute deve ser uma data igual ou posterior a :date.',
    'before_or_equal' => 'O campo :attribute deve ser uma data igual ou anterior a :date.',
    'between' => ['numeric' => 'O campo :attribute deve estar entre :min e :max.'],
    'date' => 'O campo :attribute não é uma data válida.',
    'date_format' => 'O campo :attribute deve estar no formato :format.',
    'digits' => 'O campo :attribute deve ter :digits dígitos.',
    'email' => 'O campo :attribute deve ser um e-mail válido.',
    'enum' => 'O valor selecionado para :attribute é inválido.',
    'exists' => 'O valor selecionado para :attribute é inválido.',
    'file' => 'O campo :attribute deve ser um arquivo.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'max' => [
        'file' => 'O arquivo :attribute não pode ter mais de :max KB.',
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
    ],
    'min' => ['string' => 'O campo :attribute deve ter pelo menos :min caracteres.'],
    'password' => ['min' => 'A senha deve ter pelo menos :min caracteres.'],
    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não está presente.',
    'string' => 'O campo :attribute deve ser um texto.',
    'unique' => 'Já existe um registro com este :attribute.',

    'attributes' => [
        'cnpj' => 'CNPJ', 'documento' => 'documento', 'razao_social' => 'razão social', 'nome' => 'nome',
        'email' => 'e-mail', 'password' => 'senha', 'numero' => 'número', 'descricao' => 'descrição',
        'valor_mensal' => 'valor mensal', 'dia_vencimento' => 'dia de vencimento', 'inicio' => 'início',
        'fim' => 'fim', 'vigencia_inicio' => 'início da vigência', 'vigencia_fim' => 'fim da vigência',
        'codigo_servico' => 'código de serviço', 'municipio_ibge' => 'código IBGE', 'chave_acesso' => 'chave de acesso',
        'xml' => 'XML', 'valor' => 'valor', 'data' => 'data', 'motivo' => 'motivo', 'justificativa' => 'justificativa',
        'competencia' => 'competência', 'prestador_id' => 'prestador', 'empresa_id' => 'empresa', 'cliente_id' => 'cliente',
    ],
];
