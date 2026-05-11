<?php

function ha_validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function ha_validate_phone($phone) {
    $cleanedPhone = preg_replace('/[^\d+]/', '', $phone);
    return preg_match('/^\+\d{7,15}$/', $cleanedPhone);
}

