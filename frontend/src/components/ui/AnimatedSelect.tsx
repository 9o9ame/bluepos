import {
  Children,
  isValidElement,
  type ChangeEvent,
  type FocusEvent,
  type KeyboardEvent,
  type MouseEvent,
  type ReactNode,
  type SelectHTMLAttributes,
} from 'react'
import { UiSelect, type UiSelectOption } from './UiSelect'

type Props = SelectHTMLAttributes<HTMLSelectElement> & {
  shellClassName?: string
  menuZIndex?: number
}

export function AnimatedSelect({
  className,
  shellClassName,
  disabled,
  onMouseDown,
  onKeyDown,
  onBlur,
  onFocus,
  onChange,
  children,
  value,
  title,
  menuZIndex,
  'aria-label': ariaLabel,
}: Props) {
  const options: UiSelectOption[] = Children.toArray(children).flatMap((child) => {
    if (!isValidElement(child) || child.type !== 'option') return []

    const option = child.props as {
      value?: string | number
      children?: unknown
      disabled?: boolean
      title?: string
    }

    return [{
      value: String(option.value ?? ''),
      label: Children.toArray(option.children as ReactNode)
        .map((node) => (typeof node === 'string' || typeof node === 'number' ? String(node) : ''))
        .join(''),
      disabled: option.disabled,
      title: option.title,
    }]
  })

  return (
    <UiSelect
      value={String(value ?? '')}
      options={options}
      disabled={disabled}
      title={title}
      menuZIndex={menuZIndex}
      aria-label={ariaLabel}
      className={['vca-select-shell', shellClassName, className].filter(Boolean).join(' ')}
      onFocus={onFocus ? (event) => onFocus(event as unknown as FocusEvent<HTMLSelectElement>) : undefined}
      onBlur={onBlur ? (event) => onBlur(event as unknown as FocusEvent<HTMLSelectElement>) : undefined}
      onKeyDown={onKeyDown ? (event) => onKeyDown(event as unknown as KeyboardEvent<HTMLSelectElement>) : undefined}
      onMouseDown={onMouseDown ? (event) => onMouseDown(event as unknown as MouseEvent<HTMLSelectElement>) : undefined}
      onChange={(nextValue) => {
        onChange?.({
          target: { value: nextValue },
          currentTarget: { value: nextValue },
        } as ChangeEvent<HTMLSelectElement>)
      }}
    />
  )
}
