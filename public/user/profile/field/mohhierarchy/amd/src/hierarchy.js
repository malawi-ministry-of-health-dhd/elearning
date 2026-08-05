// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Bidirectional hierarchy placement for the MoH hierarchy profile field.
 *
 * This module is a convenience, not a security boundary. Every list it fetches has already been
 * filtered by the server, and whatever it puts in the form is re-validated on save, so tampering
 * with it achieves nothing.
 *
 * @module     profilefield_mohhierarchy/hierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Find a form element by its name attribute.
 *
 * Names, not ids, are used because a frozen level renders as static text with no input at all, so a
 * missing element simply means that level is not selectable.
 *
 * @param {String} name The element name.
 * @return {HTMLSelectElement|null}
 */
const findElement = (name) => document.querySelector(`select[name="${name}"]`);

/**
 * Set a selector when the required option exists.
 *
 * @param {HTMLSelectElement|null} select The selector.
 * @param {Number} value The local hierarchy id.
 * @return {void}
 */
const setValue = (select, value) => {
    if (!select) {
        return;
    }
    const wanted = String(value);
    select.value = Array.from(select.options).some((option) => option.value === wanted) ? wanted : '';
};

/**
 * Build the unique District-to-Zone map from the permitted facility paths.
 *
 * @param {Object} paths Facility id to ancestor ids.
 * @return {Object} District id to Zone id.
 */
const districtPaths = (paths) => {
    const result = {};
    Object.values(paths).forEach((path) => {
        if (path?.districtid && path?.zoneid) {
            result[String(path.districtid)] = parseInt(path.zoneid, 10);
        }
    });
    return result;
};

/**
 * Wire up the selectors.
 *
 * The form keeps the conventional Zone, District, Facility order. Zone narrows District, while the
 * searchable Facility remains available across the actor's entire scope and derives both parents.
 * The browser map is never trusted on save; the server resolves and authorises the submitted
 * facility again.
 *
 * @param {Object} config Element names, current ids, and which levels are fixed.
 * @return {void}
 */
export const init = (config) => {
    const zone = findElement(config.zoneElement);
    const district = findElement(config.districtElement);
    const facility = findElement(config.facilityElement);
    const paths = config.facilityPaths || {};
    const districts = districtPaths(paths);

    if (!facility) {
        return;
    }

    facility.closest('[data-fieldtype="autocomplete"]')
        ?.classList.add('mohhierarchy-autocomplete');

    const facilityPath = () => {
        const facilityid = parseInt(facility.value, 10) || 0;
        return paths[facilityid] || paths[String(facilityid)] || null;
    };

    const filterOptions = () => {
        const zoneid = parseInt(zone?.value, 10) || 0;

        if (district && !config.districtFixed) {
            Array.from(district.options).forEach((option) => {
                const optionzoneid = districts[String(option.value)] || 0;
                const available = !option.value || !zoneid || optionzoneid === zoneid;
                option.disabled = !available;
                option.hidden = !available;
            });
        }

    };

    const clearFacilityIfOutsideParents = () => {
        const path = facilityPath();
        const zoneid = parseInt(zone?.value, 10) || 0;
        const districtid = parseInt(district?.value, 10) || 0;
        const outside = path && (
            (zoneid && parseInt(path.zoneid, 10) !== zoneid)
            || (districtid && parseInt(path.districtid, 10) !== districtid)
        );
        if (outside && !config.facilityFixed) {
            facility.value = '';
            facility.dispatchEvent(new Event('change', {bubbles: true}));
        }
    };

    if (!config.zoneFixed && zone) {
        zone.addEventListener('change', () => {
            const zoneid = parseInt(zone.value, 10) || 0;
            const districtzoneid = districts[String(district?.value)] || 0;
            if (district && district.value && districtzoneid !== zoneid) {
                setValue(district, 0);
            }
            clearFacilityIfOutsideParents();
            filterOptions();
        });
    }

    if (!config.districtFixed && district) {
        district.addEventListener('change', () => {
            const zoneid = districts[String(district.value)] || 0;
            if (zoneid) {
                setValue(zone, zoneid);
            }
            clearFacilityIfOutsideParents();
            filterOptions();
        });
    }

    if (!config.facilityFixed) {
        facility.addEventListener('change', () => {
            const path = facilityPath();
            if (path) {
                setValue(zone, path.zoneid);
                setValue(district, path.districtid);
            }
            filterOptions();
        });
    }

    const initialpath = facilityPath();
    if (initialpath) {
        setValue(zone, initialpath.zoneid);
        setValue(district, initialpath.districtid);
    }
    filterOptions();
};
