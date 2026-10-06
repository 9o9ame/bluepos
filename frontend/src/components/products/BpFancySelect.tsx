import { UiSelect, type UiSelectOption, type UiSelectProps } from '../ui/UiSelect'

export type BpFancySelectOption = UiSelectOption
export type BpFancySelectProps = UiSelectProps

export function BpFancySelect(props: BpFancySelectProps) {
  return <UiSelect {...props} />
}
