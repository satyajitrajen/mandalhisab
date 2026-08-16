<?php

namespace App\Enums;

enum ExportFormat: string
{
    case XLSX = 'xlsx';
    case CSV = 'csv';
    case PDF = 'pdf';
}
