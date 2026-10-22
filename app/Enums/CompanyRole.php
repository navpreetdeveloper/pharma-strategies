<?php

namespace App\Enums;

enum CompanyRole: string
{
    case Staff = 'staff';
    case Pharmacist = 'pharmacist';
    case Manager = 'pharmacy_manager';
    case Technician = 'pharmacy_technician';
    case Assistant = 'pharmacy_assistant';
    case Administrator = 'company_super_admin';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function employeeValues(): array
    {
        return array_values(array_diff(self::values(), [self::Administrator->value]));
    }
}
