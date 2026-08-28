<?php

namespace App\Enums;

enum DocumentType: string
{
    case IdProof = 'id_proof';
    case AddressProof = 'address_proof';
    case EducationCertificate = 'education_certificate';
    case ExperienceLetter = 'experience_letter';
    case RelievingLetter = 'relieving_letter';
    case SalarySlip = 'salary_slip';
    case PhotoId = 'photo_id';
    case BankDetails = 'bank_details';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::IdProof => 'ID Proof',
            self::AddressProof => 'Address Proof',
            self::EducationCertificate => 'Education Certificate',
            self::ExperienceLetter => 'Experience Letter',
            self::RelievingLetter => 'Relieving Letter',
            self::SalarySlip => 'Salary Slip',
            self::PhotoId => 'Photo ID',
            self::BankDetails => 'Bank Details',
            self::Other => 'Other',
        };
    }
}
