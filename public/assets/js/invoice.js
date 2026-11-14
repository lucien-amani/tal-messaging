/* Bouton « Imprimer » de la facture (la CSP interdit les gestionnaires onclick en ligne). */
document.getElementById('print-btn')?.addEventListener('click', () => window.print());
