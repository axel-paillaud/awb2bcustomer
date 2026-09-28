<?php
/**
 * @author    Axelweb <contact@axelweb.fr>
 * @copyright 2026 Axelweb
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of Axelweb
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * B2B customer.
 *
 * Relies on the native B2B mode of PrestaShop (PS_B2B_ENABLE), which adds the
 * `company` and `siret` fields to the front-office customer form:
 *
 *  - makes both fields mandatory (HTML `required` + server-side validation);
 *  - displays them on the customer page of the back-office, where the core
 *    only shows them in the edit form.
 *
 * No configuration page, no table, no override: two hooks and a Twig template.
 */
class AwB2bCustomer extends Module
{
    private const TRANSLATION_DOMAIN = 'Modules.Awb2bcustomer.Admin';

    /**
     * Native customer form fields made mandatory. They only exist in the
     * format when the B2B mode is enabled (see CustomerFormatter::getFormat()).
     */
    private const MANDATORY_FIELDS = ['company', 'siret'];

    /** Size of the `customer.siret` column (see Customer::$definition). */
    private const SIRET_MAX_LENGTH = 14;

    public function __construct()
    {
        $this->name = 'awb2bcustomer';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Axelweb';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('B2B customer', [], self::TRANSLATION_DOMAIN);
        $this->description = $this->trans('Mandatory company and SIRET fields at registration, company information on the customer page in the back-office.', [], self::TRANSLATION_DOMAIN);

        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall this module?', [], self::TRANSLATION_DOMAIN);

        $this->ps_versions_compliancy = [
            'min' => '8.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install(): bool
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->registerHook('additionalCustomerFormFields')
            && $this->registerHook('displayAdminCustomers');
    }

    /**
     * Triggered while the front-office customer form (registration, personal
     * information) is built, with the fields passed by reference.
     *
     * The core adds `company` and `siret` as optional fields when the B2B mode
     * is enabled: this makes them mandatory. The theme renders the `required`
     * attribute and AbstractForm::validate() rejects an empty value, so both
     * the HTML and the server-side validation are covered.
     *
     * The SIRET field also gets the maximum length of the `customer.siret`
     * column, so that a too long value is rejected with a clear message
     * instead of a database error.
     *
     * @param array{fields: array<string, FormField>} $params
     *
     * @return array<string, FormField> no additional field
     */
    public function hookAdditionalCustomerFormFields(array $params): array
    {
        $fields = $params['fields'] ?? [];

        foreach (self::MANDATORY_FIELDS as $name) {
            if (isset($fields[$name]) && $fields[$name] instanceof FormField) {
                $fields[$name]->setRequired(true);
            }
        }

        if (isset($fields['siret']) && $fields['siret'] instanceof FormField) {
            $fields['siret']->setMaxLength(self::SIRET_MAX_LENGTH);
        }

        return [];
    }

    /**
     * Customer page of the back-office (Customers > Customers > View):
     * card with the company and the SIRET of the customer.
     *
     * @param array{id_customer: int|string} $params
     */
    public function hookDisplayAdminCustomers(array $params): string
    {
        $customer = new Customer((int) ($params['id_customer'] ?? 0));

        if (!Validate::isLoadedObject($customer)) {
            return '';
        }

        return $this->get('twig')->render('@Modules/awb2bcustomer/views/templates/admin/customer_card.html.twig', [
            'customerId' => (int) $customer->id,
            'company' => (string) $customer->company,
            'siret' => (string) $customer->siret,
            'b2bEnabled' => (bool) Configuration::get('PS_B2B_ENABLE'),
        ]);
    }
}
