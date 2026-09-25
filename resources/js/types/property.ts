export type Option = {
    id: number;
    name: string;
};

export type PropertyGroup = {
    id: number;
    name: string;
};

export type ThirdPartyProvider = Option & {
    pivot?: { tracking_id: string | null };
};

export type MetasearchPlatform = Option & {
    pivot?: { listing_id: string | null };
};

export type FormFieldOption = {
    value: number;
    label: string;
};

export type MetaField = {
    id: number;
    key: string;
    key_label: string;
};

export type MetaFieldValue = {
    id: number;
    meta_fields_key_id: number;
    enabled: boolean;
    data: string | null;
    meta_field?: { key: string; key_label: string };
};

export type CancellationRuleTier = {
    day_from_checkin: number;
    penalty_percent: number;
};

export type CancellationRule = {
    id: number;
    start_time_stamp: string;
    end_time_stamp: string;
    rules: CancellationRuleTier[];
};

export type AmenityOption = {
    id: number;
    name: string;
};

export type AmenityCategoryGroup = {
    id: number;
    name: string;
    amenities: AmenityOption[];
};

export type Room = {
    id: number;
    name: string;
    display_order: number;
    bed_count: number | null;
};

export type RoomCategory = {
    id: number;
    name: string;
    display_order: number;
    upsell_order: number | null;
    sell_by_bed: boolean;
    description: string | null;
    abbreviation: string | null;
    integration_code: string | null;
    inclusion_details: string | null;
    base_occupancy: number;
    ideal_occupancy: number;
    max_adults: number;
    max_children: number;
    default_number_of_children: number;
    total_number_of_guests: number;
    sync_category_on_ota: boolean;
    exclude_from_revenue: boolean;
    exclude_from_occupancy: boolean;
    exclude_from_upgrade: boolean;
    meal_plans: string[] | null;
    rooms?: Room[];
    images?: { id: number; path: string; url: string; selected: boolean }[];
    bed_types?: {
        id: number;
        name: string;
        type: 'bed' | 'extra_bed';
        quantity: number | null;
    }[];
};

export type RoomCategoryAmenityLink = {
    room_category_id: number;
    amenity_id: number;
};

export type MealPlanRow = {
    display_order: number;
    constant_name: string;
    display_name: string;
    short_display_name: string;
};

export type Property = {
    id: number;
    name: string;
    property_legal_name: string;
    property_group_id: number;
    property_group?: PropertyGroup;
    abbreviation: string;
    property_type: number;

    third_party_analytics_enabled: boolean;
    third_party_ad_tracking_enabled: boolean;
    third_party_analytics?: ThirdPartyProvider[];
    third_party_ad_tracking?: ThirdPartyProvider[];

    property_contact_number: string | null;
    outlet_sac_code_enabled: boolean;

    address_line_1: string;
    address_line_2: string | null;
    postal_code: string;
    city_id: number;
    state_id: number;
    country_id: number;
    city?: Option;
    state?: Option;
    country?: Option;

    corporate_address_permanent_address_same: boolean;
    corporate_address_line_1: string;
    corporate_address_line_2: string | null;
    corporate_postal_code: string;
    corporate_city_id: number;
    corporate_state_id: number;
    corporate_country_id: number;
    corporate_city?: Option;
    corporate_state?: Option;
    corporate_country?: Option;

    currency_id: number;
    /** IANA timezone (e.g. Asia/Kolkata); times are stored in UTC and shown in it. */
    timezone?: string;
    ignore_decimal_places_in_bill_settlement: boolean;
    checkout_tolerance_value: string;

    language: number;

    location_map_url: string | null;
    latitude: string | null;
    longitude: string | null;
    property_segment: number;

    star_rating: number;
    tax_type: number;
    metasearch_engines_listings_enabled: boolean;
    metasearch_platforms?: MetasearchPlatform[];

    meta_field_values?: MetaFieldValue[];
    cancellation_rules?: CancellationRule[];
    amenities?: AmenityOption[];
    room_categories?: RoomCategory[];

    'customise_name_for+room_invoice': string | null;

    [key: string]: unknown;
};
