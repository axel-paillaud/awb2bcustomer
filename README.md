# AwB2bCustomer

B2B customer fields for PrestaShop 8 / 9, by [Axelweb](https://axelweb.fr).

Built for B2B shops where customers are identified by a professional number: the native `siret` field
of the customer form becomes mandatory (`company` stays optional), and both are displayed on the
customer page of the back-office.

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
| `additionalCustomerFormFields` | Makes `siret` mandatory (`company` stays optional, see `MANDATORY_FIELDS`): the theme renders the `required` attribute and the core validation (`AbstractForm::validate()`) rejects an empty value. Also sets the maximum length of `siret` to 14, the size of the column, and a help text under the field. Adds no field |
| `displayAdminCustomers` | Card "Company information" (company, SIRET, edit link) on the customer page of the back-office (Customers > Customers > View), which only shows these fields in the edit form natively |

The hooks do nothing when the B2B mode is disabled (the fields do not exist in the form); the card then
shows a warning.

### Format of the SIRET: a free field

No format check on purpose. The field is used as a free professional identifier: French SIRET, SIREN,
EU VAT number, or the situation of a customer without a company ("student", "company being set up").
The core itself only applies `isGenericName` (no `<>={}` characters) and the length of the column.
The help text under the field lists these uses and the 14 characters limit, and the label of the field is
set by the theme translation of "Identification number" (`Shop.Forms.Labels`).

The 14 characters limit cannot be raised without an override of `Customer::$definition` (plus an
`ALTER TABLE` on the column), which is why it is kept as is.

To only accept French SIRET numbers (14 digits with a Luhn check), add a native constraint in
`hookAdditionalCustomerFormFields()`:

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
│   ├── ModulesAwb2bcustomerAdmin.fr-FR.xlf
│   └── ModulesAwb2bcustomerShop.fr-FR.xlf
└── views/templates/admin/
    └── customer_card.html.twig         # Back-office card
```

## Translation

The module uses PrestaShop's new translation system. Domains: `Modules.Awb2bcustomer.Admin` (back-office)
and `Modules.Awb2bcustomer.Shop` (help text of the front-office form).

## License

[Academic Free License 3.0 (AFL-3.0)](https://opensource.org/licenses/AFL-3.0)
