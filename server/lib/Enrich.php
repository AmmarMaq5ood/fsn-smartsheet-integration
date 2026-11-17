<?php
declare(strict_types=1);

/**
 * School facts for a tracker event, so the row is about the school and not about whoever signed.
 *
 * Order of truth:
 *   1. the signer's company in Launchpad (the school record: sed_code, dbn, address, principal, ...)
 *   2. the SED code on the contact / in the payload, when the contact has no company
 *   3. the master sheet, by SED: the exact SCHOOL NAME the PLT dropdown and lookups expect, plus county,
 *      district, type and principal for anything the company left blank
 *
 * The result is merged into the payload under 'school.*' and used by the mapping ({{school.name}} etc.).
 * Everything is best effort: a missing company or an unreachable API leaves the keys null.
 */
final class Enrich
{
    public const KEYS = [
        'sed', 'name', 'name_shared', 'dbn', 'official_name', 'district_code', 'district', 'borough', 'county',
        'county_code', 'type', 'grades', 'principal_name', 'principal_email', 'principal_phone', 'address',
        'company_id', 'company_name', 'source',
    ];

    public static function school(Payload $p, ?Launchpad $lp, ?SchoolDirectory $dir): array
    {
        $out = array_fill_keys(self::KEYS, null);
        $out['source'] = [];
        $out['name_shared'] = false;

        // 1. the signer's company (parent record)
        $company = null;
        $contactId = $p->get('contact_id');
        if ($lp && $lp->enabled() && $contactId) {
            $c = $lp->contact($contactId);
            if ($c && !empty($c['businessId'])) {
                $company = $lp->company($c['businessId']);
                if ($company) {
                    $out['company_id'] = $c['businessId'];
                    $out['source'][] = 'company';
                }
            }
        }
        if ($company) {
            $g = fn(string $k) => isset($company[$k]) && trim((string)$company[$k]) !== '' ? trim((string)$company[$k]) : null;
            $out['company_name']    = $g('name');
            $out['sed']             = SchoolDirectory::normaliseSed($g('sed_code'));
            $out['dbn']             = $g('dbn');
            $out['official_name']   = $g('official_school_name') ?? $g('name');
            $out['district_code']   = $g('school_district_code');
            $out['district']        = $g('school_district_description');
            $out['borough']         = $g('borough');
            $out['county']          = $g('county');
            $out['type']            = $g('school_type');
            $out['grades']          = $g('grades');
            $out['principal_name']  = $g('principal_name');
            $out['principal_email'] = $g('principal_email');
            $out['principal_phone'] = $g('principal_phone');
            $street = $g('address');
            if ($street !== null) {
                $cityLine = trim(implode(' ', array_filter([$g('state'), $g('postalCode') ?? $g('postalcode')])));
                $out['address'] = implode(', ', array_filter([$street, $g('city'), $cityLine !== '' ? $cityLine : null]));
            }
        }

        // 2. SED from the contact itself
        if ($out['sed'] === null) {
            $raw = $p->first('SED Code', 'customData."LP SED Code"', 'customData."SED Code (Look up in NYS Public Schools List )"');
            $sed = SchoolDirectory::normaliseSed($raw);
            if ($sed === null && preg_match('/^\s*(\d{11,12})\s*:/', (string)$p->get('SED Code + Popular Name'), $m)) {
                $sed = SchoolDirectory::normaliseSed($m[1]);
            }
            if ($sed !== null) {
                $out['sed'] = $sed;
                $out['source'][] = 'contact';
            }
        }

        // 3. the master sheet, by SED
        if ($out['sed'] !== null && $dir && $dir->enabled()) {
            $m = $dir->bySed($out['sed']);
            if ($m) {
                $out['name']        = $m['name'];
                $out['name_shared'] = (bool)$m['name_shared'];
                $out['source'][]    = 'master';
                foreach (['county', 'county_code', 'district_code', 'district', 'type', 'principal_name', 'principal_email', 'principal_phone'] as $k) {
                    if ($out[$k] === null && !empty($m[$k])) {
                        $out[$k] = $m[$k];
                    }
                }
                $out['official_name'] ??= $m['legal_name'] ?? null;
            }
        }
        return $out;
    }
}
