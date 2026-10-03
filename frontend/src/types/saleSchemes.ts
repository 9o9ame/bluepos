export type SaleSchemeApplyMode = 'auto' | 'salesman'

export type SaleScheme = {
ulid: string
name: string
scheme_type: string
apply_mode: SaleSchemeApplyMode
min_sale_amount: string
max_reward_qty: string
starts_on: string | null
ends_on: string | null
is_stackable: boolean
is_active: boolean
reward_product?: {
ulid: string
name: string
product_number: string
is_active: boolean
} | null
}

export type SaleOfferEvaluation = {
packaging: Array<{
ulid: string
name: string
product_number: string
max_free_qty_per_sale: string | null
line_kind: 'free_packaging'
}>

schemes: Array<{
ulid: string
name: string
scheme_type: string
apply_mode: SaleSchemeApplyMode
auto_apply: boolean
requires_salesman_decision: boolean
min_sale_amount: string
max_reward_qty: string
is_stackable: boolean
reward_product: {
ulid: string
name: string
product_number: string
}
line_kind: 'free_scheme'
}>
}
