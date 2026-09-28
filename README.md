# AwB2bCustomer

B2B customer fields for PrestaShop 8 / 9, by [Axelweb](https://axelweb.fr).

Built for B2B shops where every customer is a company: the native `company` and `siret` fields
of the customer form become mandatory, and are displayed on the customer page of the back-office.

## Requirements

- PrestaShop 8.0+ with the **B2B mode enabled** (Shop Parameters > Customer Settings > Enable B2B mode,
  configuration key `PS_B2B_ENABLE`)
- PHP 8.1+

## Getting started

1. Enable the B2B mode.
2. Install the module from the PrestaShop back-office.

There is no configuration page, no database table and no override: the module is two hooks and one
Twig template. Composer is only needed for the development tools (`composer install` for php-cs-fixer).

---

## How it works

With the B2B mode enabled, the core (`CustomerFormatter::getFormat()`) adds two optional fields to the
front-office customer form (registration and personal information pages): `company` and `siret`,
stored in the native `customer.company` / `customer.siret` columns and editable from the back-office
customer form.

| Hook | Role |
|---|---|
| `additionalCustomerFormFields` | Makes `company` and `siret` mandatory: the theme renders the `required` attribute and the core validation (`AbstractForm::validate()`) rejects an empty value. Also sets the maximum length of `siret` to 14, the size of the column. Adds no field |
| `displayAdminCustomers` | Card "Company information" (company, SIRET, edit link) on the customer page of the back-office (Customers > Customers > View), which only shows these fields in the edit form natively |

The hooks do nothing when the B2B mode is disabled (the fields do not exist in the form); the card then
shows a warning.

### Format of the SIRET

No format check on purpose: the field also receives EU VAT numbers. To only accept French SIRET
numbers (14 digits with a Luhn check), add a native constraint in `hookAdditionalCustomerFormFields()`:

```php
$fields['siret']->addConstraint('isSiret');
```

---

## Migration from the registrationfields module

`bin/migrate-registrationfields.php` copies the values of two custom fields of the FMM
`registrationfields` module (company and SIRET) into the native `customer.company` / `customer.siret`
columns, for the accounts whose native column is empty.

```
php modules/awb2bcustomer/bin/migrate-registrationfields.php                 # dry run (nothing written)
php modules/awb2bcustomer/bin/migrate-registrationfields.php --apply         # apply
php modules/awb2bcustomer/bin/migrate-registrationfields.php --help
```

Options: `--company-field=ID` (default 12), `--siret-field=ID` (default 13), `--limit=N` (rows listed in
the details, 0 = all).

Rules:

- SIRET values are normalised: only letters and digits are kept (spaces, dots, dashes and invisible
  characters removed), upper-cased. A value longer than 14 characters does not fit in the native column
  (`varchar(14)`, typos and free text such as "not subject to VAT"): the column is left empty and the raw
  value is appended to the **private note** of the customer, visible on the back-office customer page,
  after the existing note if any. The note prefix makes the script idempotent.
- An account whose native column already holds a different value is listed and left untouched.
- Nothing else is written: the `registrationfields` tables and configuration are left untouched, the
  module is meant to be uninstalled once the migration is checked.
- Audit files in `var/logs/`: `awb2bcustomer-migration-<date>.sql` (rollback UPDATE statements) and
  `awb2bcustomer-migration-<date>.csv`.

## Project structure

```
awb2bcustomer/
├── awb2bcustomer.php         # Main module class (hooks)
├── bin/
│   ├── build-zip.sh                    # Release archive
│   └── migrate-registrationfields.php  # One-shot migration from the FMM registrationfields module
├── translations/fr-FR/
│   └── ModulesAwb2bcustomerAdmin.fr-FR.xlf
└── views/templates/admin/
    └── customer_card.html.twig         # Back-office card
```

## Translation

The module uses PrestaShop's new translation system. Domain: `Modules.Awb2bcustomer.Admin`.

## License

[Academic Free License 3.0 (AFL-3.0)](https://opensource.org/licenses/AFL-3.0)
