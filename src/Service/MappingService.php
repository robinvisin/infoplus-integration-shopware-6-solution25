<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

class MappingService
{
    /** @var array<string,string> */
    private const COUNTRY_MAP = [
        'AF' => 'AFGHANISTAN', 'AX' => 'ALAND ISLANDS', 'AL' => 'ALBANIA', 'DZ' => 'ALGERIA', 'AS' => 'AMERICAN SAMOA',
        'AD' => 'ANDORRA', 'AO' => 'ANGOLA', 'AI' => 'ANGUILLA', 'AQ' => 'ANTIGUA AND BARBUDA', 'AG' => 'ARGENTINA',
        'AR' => 'ARMENIA', 'AM' => 'ARUBA', 'AW' => 'AUSTRALIA', 'AU' => 'AUSTRIA', 'AT' => 'AZERBAIJAN',
        'AZ' => 'AZORES', 'BS' => 'BAHAMAS', 'BH' => 'BAHRAIN', 'BD' => 'BANGLADESH', 'BB' => 'BARBADOS',
        'BY' => 'BELARUS', 'BE' => 'BELGIUM', 'BZ' => 'BELIZE', 'BJ' => 'BENIN', 'BM' => 'BERMUDA',
        'BT' => 'BHUTAN', 'BO' => 'BOLIVIA', 'BQ' => 'BONAIRE', 'BA' => 'BOSNIA', 'BW' => 'BOTSWANA',
        'BR' => 'BRAZIL', 'VG' => 'BRITISH VIRGIN ISLES', 'BN' => 'BRUNEI', 'BG' => 'BULGARIA', 'BF' => 'BURKINA FASO',
        'BI' => 'BURUNDI', 'KH' => 'CAMBODIA', 'CM' => 'CAMEROON', 'CA' => 'CANADA', 'CV' => 'CAPE VERDE ISLAND',
        'KY' => 'CAYMAN ISLANDS', 'CF' => 'CENTRAL AFRICAN REPUBLIC', 'TD' => 'CHAD', 'CL' => 'CHILE',
        'CN' => 'CHINA', 'CO' => 'COLOMBIA', 'KM' => 'COMOROS', 'CG' => 'CONGO', 'CD' => 'CONGO, THE DEMOCRATIC REPUBLIC OF',
        'CK' => 'COOK ISLANDS', 'CR' => 'COSTA RICA', 'CI' => 'COTE D\' IVOIRE', 'HR' => 'CROATIA', 'CU' => 'CUBA',
        'CW' => 'CURACAO', 'CY' => 'CYPRUS', 'CZ' => 'CZECH REPUBLIC', 'DK' => 'DENMARK', 'DJ' => 'DJIBOUTI',
        'DM' => 'DOMINICA', 'DO' => 'DOMINICAN REPUBLIC', 'TL' => 'EAST TIMOR', 'EC' => 'ECUADOR', 'EG' => 'EGYPT',
        'SV' => 'EL SALVADOR', 'GB' => 'ENGLAND', 'GQ' => 'EQUATORIAL GUINEA', 'ER' => 'ERITREA', 'EE' => 'ESTONIA',
        'ET' => 'ETHIOPIA', 'FK' => 'FALKLAND ISLANDS', 'FO' => 'FAEROE ISLANDS', 'FJ' => 'FIJI', 'FI' => 'FINLAND',
        'FR' => 'FRANCE', 'GF' => 'FRENCH GUIANA', 'PF' => 'FRENCH POLYNESIA', 'GA' => 'GABON', 'GM' => 'GAMBIA',
        'GE' => 'GEORGIA', 'DE' => 'GERMANY', 'GH' => 'GHANA', 'GI' => 'GIBRALTAR', 'GR' => 'GREECE', 'GL' => 'GREENLAND',
        'GD' => 'GRENADA', 'GP' => 'GUADELOUPE', 'GU' => 'GUAM', 'GT' => 'GUATEMALA', 'GG' => 'GUERNSEY',
        'GN' => 'GUINEA', 'GW' => 'GUINEA-BISSAU', 'GY' => 'GUYANA', 'HT' => 'HAITI', 'NL' => 'HOLLAND',
        'HN' => 'HONDURAS', 'HK' => 'HONG KONG', 'HU' => 'HUNGARY', 'IS' => 'ICELAND', 'IN' => 'INDIA',
        'ID' => 'INDONESIA', 'IR' => 'IRAN', 'IQ' => 'IRAQ', 'IE' => 'IRELAND', 'IL' => 'ISRAEL', 'IT' => 'ITALY',
        'JM' => 'JAMAICA', 'JP' => 'JAPAN', 'JE' => 'JERSEY', 'JO' => 'JORDAN', 'KZ' => 'KAZAKHSTAN', 'KE' => 'KENYA',
        'KI' => 'KIRIBATI', 'KW' => 'KUWAIT', 'KG' => 'KYRGYZSTAN', 'LA' => 'LAOS', 'LV' => 'LATVIA', 'LB' => 'LEBANON',
        'LS' => 'LESOTHO', 'LR' => 'LIBERIA', 'LY' => 'LIBYA', 'LI' => 'LIECHTENSTEIN', 'LT' => 'LITHUANIA',
        'LU' => 'LUXEMBOURG', 'MO' => 'MACAU', 'MK' => 'MACEDONIA (FYROM)', 'MG' => 'MADAGASCAR', 'MW' => 'MALAWI',
        'MY' => 'MALAYSIA', 'MV' => 'MALDIVES', 'ML' => 'MALI', 'MT' => 'MALTA', 'MH' => 'MARSHALL ISLANDS',
        'MQ' => 'MARTINIQUE', 'MR' => 'MAURITANIA', 'MU' => 'MAURITIUS', 'MX' => 'MEXICO', 'FM' => 'MICRONESIA, FEDERATED STATES OF',
        'MD' => 'MOLDOVA', 'MC' => 'MONACO', 'MN' => 'MONGOLIA', 'ME' => 'MONTENEGRO', 'MS' => 'MONTSERRAT', 'MA' => 'MOROCCO',
        'MZ' => 'MOZAMBIQUE', 'MM' => 'MYANMAR', 'NA' => 'NAMIBIA', 'NR' => 'NAURU', 'NP' => 'NEPAL', 'NC' => 'NEW CALEDONIA',
        'NZ' => 'NEW ZEALAND', 'NI' => 'NICARAGUA', 'NE' => 'NIGER', 'NG' => 'NIGERIA', 'NU' => 'NIUE', 'NF' => 'NORFOLK ISLAND',
        'KP' => 'NORTH KOREA', 'MP' => 'NORTHERN MARIANA ISLANDS', 'NO' => 'NORWAY', 'OM' => 'OMAN', 'PK' => 'PAKISTAN',
        'PW' => 'PALAU', 'PA' => 'PANAMA', 'PG' => 'PAPUA NEW GUINEA', 'PY' => 'PARAGUAY', 'PE' => 'PERU', 'PH' => 'PHILIPPINES',
        'PL' => 'POLAND', 'PT' => 'PORTUGAL', 'PR' => 'PUERTO RICO', 'QA' => 'QATAR', 'RE' => 'REUNION', 'RO' => 'ROMANIA',
        'RU' => 'RUSSIA', 'RW' => 'RWANDA', 'BL' => 'ST. BARTHELEMY', 'SH' => 'SAINT PIERRE AND MIQUELON', 'KN' => 'ST. KITTS AND NEVIS',
        'LC' => 'ST. LUCIA', 'MF' => 'ST. MAARTEN', 'PM' => 'ST. MARTIN', 'VC' => 'ST. VINCENT AND THE GRENADINES', 'WS' => 'SOMOA',
        'SM' => 'SAN MARINO', 'SA' => 'SAUDI ARABIA', 'SN' => 'SENEGAL', 'RS' => 'SERBIA', 'SC' => 'SEYCHELLES', 'SL' => 'SIERRA LEONE',
        'SG' => 'SINGAPORE', 'SX' => 'ST. MAARTEN', 'SK' => 'SLOVAKIA', 'SI' => 'SLOVENIA', 'SB' => 'SOLOMON ISLANDS', 'SO' => 'SOMALIA',
        'ZA' => 'SOUTH AFRICA', 'KR' => 'SOUTH KOREA', 'ES' => 'SPAIN', 'LK' => 'SRI LANKA', 'SD' => 'SUDAN', 'SR' => 'SURINAME',
        'SZ' => 'SWAZILAND', 'SE' => 'SWEDEN', 'CH' => 'SWITZERLAND', 'SY' => 'SYRIA', 'TW' => 'TAIWAN', 'TJ' => 'TAJIKISTAN',
        'TZ' => 'TANZANIA', 'TH' => 'THAILAND', 'TG' => 'TOGO', 'TO' => 'TONGA', 'TT' => 'TRINIDAD AND TOBAGO', 'TN' => 'TUNISIA',
        'TR' => 'TURKEY', 'TM' => 'TURKMENISTAN', 'TC' => 'TURKS AND CAICOS ISLANDS', 'TV' => 'TUVALU', 'UG' => 'UGANDA', 'UA' => 'UKRAINE',
        'AE' => 'UNITED ARAB EMIRATES', 'US' => 'UNITED STATES', 'UY' => 'URUGUAY', 'UZ' => 'UZBEKISTAN', 'VU' => 'VANUATU',
        'VA' => 'VATICAN CITY STATE', 'VE' => 'VENEZUELA', 'VN' => 'VIETNAM', 'WF' => 'WALLIS AND FUTUNA ISLANDS', 'YE' => 'YEMEN',
        'ZM' => 'ZAMBIA', 'ZW' => 'ZIMBABWE'
    ];

    /** @var array<string,string> */
    private const US_STATE_MAP = [
        'US-AL' => 'Alabama', 'US-AK' => 'Alaska', 'US-AZ' => 'Arizona', 'US-AR' => 'Arkansas', 'US-CA' => 'California',
        'US-CO' => 'Colorado', 'US-CT' => 'Connecticut', 'US-DE' => 'Delaware', 'US-DC' => 'District of Columbia', 'US-FL' => 'Florida',
        'US-GA' => 'Georgia', 'US-HI' => 'Hawaii', 'US-ID' => 'Idaho', 'US-IL' => 'Illinois', 'US-IN' => 'Indiana', 'US-IA' => 'Iowa',
        'US-KS' => 'Kansas', 'US-KY' => 'Kentucky', 'US-LA' => 'Louisiana', 'US-ME' => 'Maine', 'US-MD' => 'Maryland',
        'US-MA' => 'Massachusetts', 'US-MI' => 'Michigan', 'US-MN' => 'Minnesota', 'US-MS' => 'Mississippi', 'US-MO' => 'Missouri',
        'US-MT' => 'Montana', 'US-NE' => 'Nebraska', 'US-NV' => 'Nevada', 'US-NH' => 'New Hampshire', 'US-NJ' => 'New Jersey',
        'US-NM' => 'New Mexico', 'US-NY' => 'New York', 'US-NC' => 'North Carolina', 'US-ND' => 'North Dakota', 'US-OH' => 'Ohio',
        'US-OK' => 'Oklahoma', 'US-OR' => 'Oregon', 'US-PA' => 'Pennsylvania', 'US-RI' => 'Rhode Island', 'US-SC' => 'South Carolina',
        'US-SD' => 'South Dakota', 'US-TN' => 'Tennessee', 'US-TX' => 'Texas', 'US-UT' => 'Utah', 'US-VT' => 'Vermont', 'US-VA' => 'Virginia',
        'US-WA' => 'Washington', 'US-WV' => 'West Virginia', 'US-WI' => 'Wisconsin', 'US-WY' => 'Wyoming'
    ];

    public static function mapIsoToInfoplusCountry(string $isoCode): string | null
    {
        $key = strtoupper(trim($isoCode));
        return self::COUNTRY_MAP[$key] ?? null;
    }

    public static function mapIsoToInfoplusUsState(string $isoCode): string | null
    {
        $key = strtoupper(trim($isoCode));
        return self::US_STATE_MAP[$key] ?? null;
    }
}
