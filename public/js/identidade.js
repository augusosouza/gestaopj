const camposIdentidade = ['nome_sistema', 'slogan', 'empresa_responsavel'];

function atualizarIdentidade() {
    for (const chave of camposIdentidade) {
        const campo = document.getElementById(chave);
        const previa = document.querySelector(`[data-previa="${chave}"]`);
        const valor = campo.value.trim() || (chave === 'nome_sistema' ? campo.defaultValue : '');
        if (chave === 'nome_sistema') {
            const [primeiraPalavra, ...restante] = valor.split(/\s+/);
            const destaque = document.createElement('span');
            destaque.className = 'marca-a';
            destaque.textContent = primeiraPalavra;
            previa.replaceChildren(destaque, restante.length ? ' ' + restante.join(' ') : '');
        } else {
            previa.textContent = valor;
            previa.hidden = !valor;
        }
    }
}

for (const chave of camposIdentidade) {
    document.getElementById(chave).addEventListener('input', atualizarIdentidade);
}
atualizarIdentidade();
