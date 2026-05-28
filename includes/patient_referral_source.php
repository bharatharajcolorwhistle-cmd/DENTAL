<?php
/**
 * Patient referral source options ("How did you hear about our clinic?")
 */

function dcmt_patient_referral_source_keys(): array
{
    return [
        'google_search',
        'facebook',
        'instagram',
        'whatsapp',
        'youtube',
        'friend_family_referral',
        'doctor_referral',
        'existing_patient',
        'online_advertisement',
        'walk_in_nearby',
        'other',
    ];
}

function dcmt_patient_referral_source_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $key = 'referral_source_' . $value;
    $label = trans('patient', $key);
    return $label !== $key ? $label : $value;
}

function dcmt_validate_patient_referral_source(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return true;
    }
    return in_array($value, dcmt_patient_referral_source_keys(), true);
}
