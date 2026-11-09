<?php

/*
 * Domain reference data for the e-Offering platform, taken from the
 * FMDQ e-Offering Change Request (October 2026). Kept in config so the
 * business can adjust lists without code changes.
 */
return [

    // s3.1 Account types offered on the onboarding screen.
    'account_types' => [
        'retail' => [
            'label' => 'Retail Investor',
            'description' => 'An individual investor participating in offers in their personal capacity.',
        ],
        'institutional' => [
            'label' => 'QII / Non-QII',
            'description' => 'A Qualified Institutional Investor ("QII") or Non-Qualified Institutional ("Non-QII") Investor participating in offers on behalf of an institution.',
        ],
        'tsp' => [
            'label' => 'Transaction Sponsor / Registration Member (TSP/RM)',
            'description' => 'Stockbrokers, Registrars, Banks and other FMDQ-registered sponsoring or registration parties who create and manage offers.',
        ],
        'regulator' => [
            'label' => 'Regulator (SEC / CBN)',
            'description' => 'The Securities and Exchange Commission ("SEC") or Central Bank of Nigeria ("CBN"), for review and approval of offers requiring regulatory sign-off.',
        ],
    ],

    // s4.1 Issuer type dropdown.
    'issuer_types' => ['Corporate', 'Sovereign', 'Sub-National', 'Supranational', 'Others'],

    // s4.1 / s4.2 Security types and the investor universe each may target.
    // The Change Request table pairs every type with "QIIs, Non-QIIs and Retail Investors"
    // except one row with "QIIs only", read here as Private Companies' Securities.
    // Legacy password policy (offering_pass_config).
    'password' => [
        'lock_after' => (int) env('PASSWORD_LOCK_AFTER', 3),
        'history' => (int) env('PASSWORD_HISTORY', 10),
        'max_age_days' => (int) env('PASSWORD_MAX_AGE_DAYS', 30),
    ],

    'security_types' => [
        'equities' => [
            'label' => 'Equities',
            'universe' => ['qii', 'non_qii', 'retail'],
            'has_tenor' => false,
            'documents' => ['Prospectus', 'Board Resolution', 'SEC Approval-in-Principle'],
        ],
        'bonds' => [
            'label' => 'Bonds',
            'universe' => ['qii', 'non_qii', 'retail'],
            'has_tenor' => true,
            'documents' => ['Pricing Supplement', 'Shelf Prospectus', 'Trust Deed', 'Rating Report'],
        ],
        'commercial_papers' => [
            'label' => 'Commercial Papers',
            'universe' => ['qii', 'non_qii', 'retail'],
            'has_tenor' => true,
            'documents' => ['Information Memorandum', 'Pricing Supplement', 'Rating Report', 'Board Resolution'],
        ],
        'non_interest' => [
            'label' => 'Non-Interest Finance Products',
            'universe' => ['qii', 'non_qii', 'retail'],
            'has_tenor' => true,
            'documents' => ['Information Memorandum', 'Shariah / Ethical Board Approval', 'Pricing Supplement'],
        ],
        'private_companies' => [
            'label' => "Private Companies' Securities",
            'universe' => ['qii'],
            'has_tenor' => true,
            'documents' => ['Information Memorandum', 'Board Resolution'],
        ],
        'depository_receipts' => [
            'label' => 'Depository Receipts',
            'universe' => ['qii', 'non_qii', 'retail'],
            'has_tenor' => false,
            'documents' => ['Prospectus', 'Depository Agreement', 'Custodian Confirmation'],
        ],
    ],

    'investor_categories' => [
        'qii' => 'Qualified Institutional Investor (QII)',
        'non_qii' => 'Non-Qualified Institutional Investor (Non-QII)',
        'retail' => 'Retail Investor',
    ],

    'day_count_fractions' => ['Actual/Actual', 'Actual/360', 'Actual/365'],

    // s4.3 Price-discovery methods.
    'price_discovery_methods' => [
        'fixed_rate' => 'Fixed Rate Bid',
        'competitive' => 'Competitive Bid',
        'book_building' => 'Book Building',
    ],

    // s6.1 Investor type chosen on the commitment form.
    'investor_types' => [
        'pfa' => 'Pension Fund Administrator (PFA)',
        'fund_manager' => 'Fund / Asset Manager',
        'bank' => 'Bank',
        'insurance' => 'Insurance Company',
        'hni' => 'High Net-worth Individual (HNI)',
        'trustee' => 'Trustee / Custodian',
        'unit_trust' => 'Investment / Unit Trust',
        'private_equity' => 'Registered Private Equity Fund',
        'stockbroker' => 'Stockbroking Firm',
        'registrar' => 'Registrar',
        'staff_scheme' => 'Staff Scheme',
        'individual' => 'Individual',
        'others' => 'Others',
    ],

    // s5.2.1 Option 1: investor classes that can be guaranteed 100%.
    // Keys map onto the commitment-form investor types above.
    'guarantee_classes' => [
        'pfa' => 'Pension Fund Administrators (PFAs)',
        'fund_manager' => 'Fund Manager',
        'bank' => 'Bank',
        'insurance' => 'Insurance Company',
        'hni' => 'HNI',
        'trustee' => 'Trustees',
        'others' => 'Others',
    ],

    // s3.2 Annual platform fee. Disabled for the first year of launch.
    'platform_fee' => [
        'gateways' => ['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave', 'bank_transfer' => 'Bank Transfer'],
        'invoice_days_before_due' => 30,
    ],

    // Login OTP (replaces the commitment-form OTP, s6.1).
    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => 5,
        // Lets automated tests and local demos skip the email step. Never enable in production.
        'bypass' => (bool) env('OTP_BYPASS', false),
    ],
];
