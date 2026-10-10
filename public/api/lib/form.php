<?php
// Champs du formulaire de whitelist et de la fiche personnage.

declare(strict_types=1);

// Identifiant Discord : nombre de 17 à 20 chiffres
const DISCORD_ID_PATTERN = '/^\d{17,20}$/';

// Clé envoyée par le client => libellé affiché
const FORM_FIELDS = [
    ['key' => 'discord', 'label' => 'Discord', 'maxLength' => 64],
    ['key' => 'discord_id', 'label' => 'Identifiant Discord', 'maxLength' => 20, 'pattern' => DISCORD_ID_PATTERN],
    ['key' => 'age_irl', 'label' => 'Âge IRL', 'maxLength' => 3, 'numeric' => true],
    ['key' => 'first_name', 'label' => 'Prénom Personnage', 'maxLength' => 64, 'character' => true],
    ['key' => 'last_name', 'label' => 'Nom Personnage', 'maxLength' => 64, 'character' => true],
    ['key' => 'age_character', 'label' => 'Âge RP', 'maxLength' => 4, 'numeric' => true, 'character' => true],
    ['key' => 'rp_born', 'label' => 'Lieu de naissance', 'maxLength' => 128, 'character' => true],
    ['key' => 'rp_experience', 'label' => 'Expérience RP', 'maxLength' => 1024, 'character' => true],
    ['key' => 'rp_story', 'label' => 'Histoire du Personnage', 'maxLength' => 1024, 'character' => true],
];

const FORM_TOGGLES = [
    ['key' => 'illegal', 'label' => 'RP Rebelle ?'],
    ['key' => 'staff', 'label' => 'Demande à être staff ?'],
];

/**
 * Valide les champs demandés. Retourne [valeurs, null] ou [null, message d'erreur].
 */
function validateFields(array $body, array $fields): array
{
    $values = [];

    foreach ($fields as $field) {
        $raw = $body[$field['key']] ?? '';
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        $label = $field['label'];

        if ($value === '') {
            return [null, "Le champ « $label » est requis."];
        }
        if (textLength($value) > $field['maxLength']) {
            return [null, "Le champ « $label » est trop long."];
        }
        if (!empty($field['numeric']) && !ctype_digit($value)) {
            return [null, "Le champ « $label » doit être un nombre."];
        }
        if (!empty($field['pattern']) && !preg_match($field['pattern'], $value)) {
            return [null, "Le champ « $label » est invalide (17 à 20 chiffres)."];
        }
        $values[$field['key']] = $value;
    }

    return [$values, null];
}

function characterFields(): array
{
    return array_values(array_filter(FORM_FIELDS, fn ($field) => !empty($field['character'])));
}
