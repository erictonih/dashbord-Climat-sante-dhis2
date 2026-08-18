<?php
/**
 * Dictionnaire des maladies à mapper automatiquement.
 * Clé   = nom de maladie utilisé dans ta table `sante.maladie`
 * Valeur = mot-clé de recherche envoyé à l'API DHIS2 (nom du data element)
 *
 * Adapte cette liste à la terminologie exacte de TON instance DHIS2
 * (utilise pages/dhis2.php → recherche manuelle pour trouver les bons mots-clés
 * avant de les figer ici).
 *
 * Si plusieurs data elements correspondent à un mot-clé, le mapping automatique
 * prend le PREMIER résultat renvoyé par DHIS2 — vérifie régulièrement les mappings
 * via pages/dhis2.php pour repérer d'éventuelles erreurs.
 */

return [
    'paludisme'              => 'paludisme confirmé',
    'diarrhee'                => 'diarrhée',
    'infection_respiratoire' => 'infection respiratoire aigue',
    'grippe'                  => 'grippe',
];
