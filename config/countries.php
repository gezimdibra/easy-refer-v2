<?php

/*
|--------------------------------------------------------------------------
| Address reference data (spec Section 4.1)
|--------------------------------------------------------------------------
|
| Signup addresses are validated against application data, not free
| text: the ISO 3166-1 alpha-2 country list, the US state and
| territory codes, and the ZIP-prefix region map used to check that a
| US ZIP code and state agree. Kept as config so the lists are data,
| reviewable in one place.
|
*/

return [

    'codes' => [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HN', 'HR',
        'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP',
        'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK',
        'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM',
        'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE',
        'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK',
        'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB',
        'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV',
        'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT',
        'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU',
        'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ],

    /*
     | Display names for the codes above (F-19): the register
     | wizard's country select renders names and keeps the codes
     | as values. Presentational only; validation still runs on
     | the code list. Generated from the ICU region data.
     */
    'names' => [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan',
        'AG' => 'Antigua & Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania',
        'AM' => 'Armenia', 'AO' => 'Angola', 'AQ' => 'Antarctica',
        'AR' => 'Argentina', 'AS' => 'American Samoa', 'AT' => 'Austria',
        'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Åland Islands',
        'AZ' => 'Azerbaijan', 'BA' => 'Bosnia & Herzegovina', 'BB' => 'Barbados',
        'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BF' => 'Burkina Faso',
        'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi',
        'BJ' => 'Benin', 'BL' => 'St. Barthélemy', 'BM' => 'Bermuda',
        'BN' => 'Brunei', 'BO' => 'Bolivia', 'BQ' => 'Caribbean Netherlands',
        'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan',
        'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus',
        'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocos (Keeling) Islands',
        'CD' => 'Congo - Kinshasa', 'CF' => 'Central African Republic', 'CG' => 'Congo - Brazzaville',
        'CH' => 'Switzerland', 'CI' => 'Côte d’Ivoire', 'CK' => 'Cook Islands',
        'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China',
        'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba',
        'CV' => 'Cape Verde', 'CW' => 'Curaçao', 'CX' => 'Christmas Island',
        'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany',
        'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica',
        'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador',
        'EE' => 'Estonia', 'EG' => 'Egypt', 'EH' => 'Western Sahara',
        'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia',
        'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands',
        'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France',
        'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada',
        'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey',
        'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland',
        'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe',
        'GQ' => 'Equatorial Guinea', 'GS' => 'South Georgia & South Sandwich Islands', 'GT' => 'Guatemala',
        'GU' => 'Guam', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
        'HK' => 'Hong Kong SAR China', 'HN' => 'Honduras', 'HR' => 'Croatia',
        'HT' => 'Haiti', 'HU' => 'Hungary', 'ID' => 'Indonesia',
        'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man',
        'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq',
        'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy',
        'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan',
        'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan',
        'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros',
        'KN' => 'St. Kitts & Nevis', 'KP' => 'North Korea', 'KR' => 'South Korea',
        'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan',
        'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'St. Lucia',
        'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia',
        'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
        'LV' => 'Latvia', 'LY' => 'Libya', 'MA' => 'Morocco',
        'MC' => 'Monaco', 'MD' => 'Moldova', 'ME' => 'Montenegro',
        'MF' => 'St. Martin', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands',
        'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar (Burma)',
        'MN' => 'Mongolia', 'MO' => 'Macao SAR China', 'MP' => 'Northern Mariana Islands',
        'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MS' => 'Montserrat',
        'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Maldives',
        'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia',
        'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NC' => 'New Caledonia',
        'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria',
        'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway',
        'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue',
        'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama',
        'PE' => 'Peru', 'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea',
        'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland',
        'PM' => 'St. Pierre & Miquelon', 'PN' => 'Pitcairn Islands', 'PR' => 'Puerto Rico',
        'PS' => 'Palestinian Territories', 'PT' => 'Portugal', 'PW' => 'Palau',
        'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion',
        'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia',
        'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands',
        'SC' => 'Seychelles', 'SD' => 'Sudan', 'SE' => 'Sweden',
        'SG' => 'Singapore', 'SH' => 'St. Helena', 'SI' => 'Slovenia',
        'SJ' => 'Svalbard & Jan Mayen', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone',
        'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia',
        'SR' => 'Suriname', 'SS' => 'South Sudan', 'ST' => 'São Tomé & Príncipe',
        'SV' => 'El Salvador', 'SX' => 'Sint Maarten', 'SY' => 'Syria',
        'SZ' => 'Eswatini', 'TC' => 'Turks & Caicos Islands', 'TD' => 'Chad',
        'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand',
        'TJ' => 'Tajikistan', 'TK' => 'Tokelau', 'TL' => 'Timor-Leste',
        'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga',
        'TR' => 'Türkiye', 'TT' => 'Trinidad & Tobago', 'TV' => 'Tuvalu',
        'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine',
        'UG' => 'Uganda', 'UM' => 'U.S. Outlying Islands', 'US' => 'United States',
        'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City',
        'VC' => 'St. Vincent & Grenadines', 'VE' => 'Venezuela', 'VG' => 'British Virgin Islands',
        'VI' => 'U.S. Virgin Islands', 'VN' => 'Vietnam', 'VU' => 'Vanuatu',
        'WF' => 'Wallis & Futuna', 'WS' => 'Samoa', 'YE' => 'Yemen',
        'YT' => 'Mayotte', 'ZA' => 'South Africa', 'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ],

    'us_states' => [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
        'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands',
        'PR' => 'Puerto Rico', 'VI' => 'U.S. Virgin Islands', 'UM' => 'U.S. Minor Outlying Islands',
    ],

    // First ZIP digit to the states it can belong to. A US signup whose
    // state falls outside its ZIP region is a data error or a typo, and
    // is refused at the form (spec Section 4.1 consistency check).
    'zip_regions' => [
        '0' => ['CT', 'MA', 'ME', 'NH', 'NJ', 'PR', 'RI', 'VT'],
        '1' => ['DE', 'NY', 'PA'],
        '2' => ['DC', 'MD', 'NC', 'SC', 'VA', 'WV'],
        '3' => ['AL', 'FL', 'GA', 'MS', 'TN'],
        '4' => ['IN', 'KY', 'MI', 'OH'],
        '5' => ['IA', 'MN', 'MT', 'ND', 'SD', 'WI'],
        '6' => ['IL', 'KS', 'MO', 'NE'],
        '7' => ['AR', 'LA', 'OK', 'TX'],
        '8' => ['AZ', 'CO', 'ID', 'NM', 'NV', 'UT', 'WY'],
        '9' => ['AK', 'AS', 'CA', 'GU', 'HI', 'MP', 'OR', 'VI', 'WA'],
    ],

];
