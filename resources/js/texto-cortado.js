/**
 * Textos cortados con "…" (clase `truncate` de Tailwind): nombres de clientes y productos que no
 * caben, sobre todo en el teléfono. Al tocarlos se muestran completos y otro toque los vuelve a
 * cortar; con mouse, además, el texto completo aparece como sugerencia (title).
 *
 * Funciona por delegación en el documento, así cubre también el contenido que se reemplaza en
 * vivo (monitor) y el que dibuja Alpine, sin marcar cada pantalla. Solo actúa si el texto está
 * realmente cortado: un `truncate` que cabe entero se comporta como texto normal.
 */
const EXPANDIDO = 'texto-expandido';

function estaCortado(elemento) {
    return elemento.scrollWidth > elemento.clientWidth + 1;
}

export default function textoCortado() {
    document.addEventListener('click', (evento) => {
        const elemento = evento.target.closest(`.truncate, .${EXPANDIDO}`);
        if (!elemento) return;

        const expandido = elemento.classList.contains(EXPANDIDO);
        if (!expandido && !estaCortado(elemento)) return;

        // Si el texto está dentro de un enlace o botón, el toque solo muestra el texto: no navega.
        evento.preventDefault();
        evento.stopPropagation();
        elemento.classList.toggle('truncate', expandido);
        elemento.classList.toggle(EXPANDIDO, !expandido);
    }, true);

    document.addEventListener('pointerover', (evento) => {
        const elemento = evento.target.closest?.('.truncate');
        if (!elemento || elemento.hasAttribute('title')) return;

        if (estaCortado(elemento)) {
            elemento.title = elemento.innerText.trim();
            elemento.style.cursor = 'pointer';
        }
    });
}
