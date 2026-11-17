export type AuthUser = {
    id: number;
    name: string;
    email: string;
};

export type SharedProps = {
    name: string;
    auth: {
        user: AuthUser | null;
        organization: { id: number; name: string } | null;
    };
};

export type Range<T> = { min: T; max: T };

/** An exact USD amount and its display form, both prepared by the server. */
export type Money = { amount: string; formatted: string };

export type CostEstimate = {
    charge: Range<Money>;
    provider_cost: Range<string>;
    tokens: Record<
        'input_text' | 'input_image' | 'output' | 'reasoning',
        Range<number>
    >;
};

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type GenerationItem = {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    estimated_charge: Range<Money>;
    charge: Money | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};
