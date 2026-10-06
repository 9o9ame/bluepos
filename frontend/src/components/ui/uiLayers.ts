/**
 * BluePOS overlay stacking contract.
 *
 * Keep dropdowns above the surface that owns them. New modal/dropdown code
 * should use these constants instead of inventing page-specific z-index values.
 */
export const UI_LAYER = {
  dropdown: 2000,
  modal: 4000,
  modalDropdown: 4200,
  nestedModal: 5000,
  nestedDropdown: 5250,
  deepModal: 5400,
  deepDropdown: 5550,
  feedback: 6000,
} as const
