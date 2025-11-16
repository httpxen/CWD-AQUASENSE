<?php
// includes/functions.php

function getStatusColor($status) {
    switch (strtolower($status)) {
        case 'pending': return '#dc2626';
        case 'in progress': return '#f59e0b';
        case 'resolved': return '#16a34a';
        case 'closed': return '#6366f1';
        default: return '#6b7280';
    }
}
?>