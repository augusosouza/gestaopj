const campoConfiguracoes = document.querySelector('#busca-config');
const secoesConfiguracoes = document.querySelectorAll('.config-secao');
const avisoConfiguracoes = document.querySelector('#sem-resultado');

function normalizarConfiguracao(texto) {
    return texto.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}

campoConfiguracoes.addEventListener('input', () => {
    const termos = normalizarConfiguracao(campoConfiguracoes.value.trim()).split(/\s+/);
    let temResultado = false;
    for (const secao of secoesConfiguracoes) {
        let visivel = false;
        for (const item of secao.querySelectorAll('.config-item')) {
            const texto = normalizarConfiguracao(item.dataset.busca);
            item.hidden = !termos.every((termo) => texto.includes(termo));
            visivel ||= !item.hidden;
        }
        secao.hidden = !visivel;
        temResultado ||= visivel;
    }
    avisoConfiguracoes.hidden = temResultado;
});
