import { updateAppearance } from '../../api/auth'
import { UiSelect } from '../../components/ui/UiSelect'
import {
  useAppearance,
  type DensityPreference,
  type FontPreference,
  type InterfaceStyle,
  type PrimaryTheme,
  type RadiusPreference,
  type ShadowPreference,
  type ThemePreference,
} from './AppearanceProvider'

const THEME_OPTIONS: Array<{ value: ThemePreference; label: string }> = [
  { value: 'system', label: 'System' }, { value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' },
]
const FONT_OPTIONS: Array<{ value: FontPreference; label: string }> = [
  { value: 'skin-default', label: 'Interface style default' }, { value: 'inter', label: 'Inter' },
  { value: 'roboto-condensed', label: 'Roboto Condensed' }, { value: 'segoe-ui', label: 'Segoe UI' },
  { value: 'tahoma', label: 'Tahoma' }, { value: 'arial', label: 'Arial' },
]
const SKIN_OPTIONS: Array<{ value: InterfaceStyle; label: string; description: string }> = [
  { value: 'classic', label: 'Classic', description: 'Dense Windows POS style with classic gradients and detailed icons.' },
  { value: 'hybrid', label: 'Hybrid', description: 'Classic POS workflow with cleaner surfaces and premium icons.' },
  { value: 'advanced', label: 'Advanced', description: 'Modern visual treatment while preserving the same workflow and controls.' },
]
const PRIMARY_OPTIONS: Array<{ value: PrimaryTheme; label: string }> = [
  { value: 'indigo', label: 'Indigo' }, { value: 'blue', label: 'Blue' }, { value: 'emerald', label: 'Emerald' },
  { value: 'teal', label: 'Teal' }, { value: 'purple', label: 'Purple' }, { value: 'slate', label: 'Slate' },
]

export function AppearanceSettings() {
  const appearance = useAppearance()
  const { theme, skin, font, primaryTheme, density, radius, shadow, animations } = appearance
  async function persist(overrides: Partial<Parameters<typeof updateAppearance>[0]> = {}) {
    try {
      await updateAppearance({ theme, skin, font, primaryTheme, density, radius, shadow, animations, ...overrides })
    } catch {
      // Local appearance remains active; persistence can retry on the next change.
    }
  }

  function choices<T extends string>(label: string, value: T, options: Array<{ value: T; label: string }>, select: (next: T) => void, key: string) {
    return <div className="appearance-settings-group">
      <div className="appearance-settings-heading">{label}</div>
      <div className="appearance-option-row" role="radiogroup" aria-label={label}>
        {options.map((option) => <label key={option.value} className={`appearance-choice${value === option.value ? ' is-selected' : ''}`}>
          <input className="appearance-choice-input" type="radio" name={`appearance-${key}`} value={option.value} checked={value === option.value}
            onChange={() => { select(option.value); void persist({ [key]: option.value }) }} />
          <span>{option.label}</span>
        </label>)}
      </div>
    </div>
  }

  return <section className="appearance-settings" aria-label="Appearance settings">
    {choices('Theme', theme, THEME_OPTIONS, appearance.setTheme, 'theme')}
    {choices('Primary theme', primaryTheme, PRIMARY_OPTIONS, appearance.setPrimaryTheme, 'primaryTheme')}
    {choices('Density', density, [{ value: 'compact', label: 'Compact' }, { value: 'comfortable', label: 'Comfortable' }] as Array<{value: DensityPreference; label: string}>, appearance.setDensity, 'density')}
    {choices('Border radius', radius, [{ value: 'small', label: 'Small' }, { value: 'medium', label: 'Medium' }, { value: 'large', label: 'Large' }] as Array<{value: RadiusPreference; label: string}>, appearance.setRadius, 'radius')}
    {choices('Card shadow', shadow, [{ value: 'none', label: 'None' }, { value: 'soft', label: 'Soft' }, { value: 'normal', label: 'Normal' }, { value: '3d', label: '3D' }] as Array<{value: ShadowPreference; label: string}>, appearance.setShadow, 'shadow')}
    <div className="appearance-settings-group">
      <div className="appearance-settings-heading">UI Animations</div>
      <div className="appearance-option-row">
        <label className={`appearance-choice${animations ? ' is-selected' : ''}`}><input className="appearance-choice-input" type="radio" name="appearance-animations" checked={animations} onChange={() => { appearance.setAnimations(true); void persist({ animations: true }) }} /><span>On</span></label>
        <label className={`appearance-choice${!animations ? ' is-selected' : ''}`}><input className="appearance-choice-input" type="radio" name="appearance-animations" checked={!animations} onChange={() => { appearance.setAnimations(false); void persist({ animations: false }) }} /><span>Off</span></label>
      </div>
    </div>
    <div className="appearance-settings-group">
      <div className="appearance-settings-heading">Interface Style</div>
      <div className="appearance-skin-grid" role="radiogroup" aria-label="Interface style">
        {SKIN_OPTIONS.map((option) => <label key={option.value} className={`appearance-skin-card${skin === option.value ? ' is-selected' : ''}`}>
          <input type="radio" name="appearance-skin" value={option.value} checked={skin === option.value}
            onChange={() => { appearance.setSkin(option.value); void persist({ skin: option.value }) }} />
          <span className="appearance-skin-title">{option.label}</span><span className="appearance-skin-description">{option.description}</span>
        </label>)}
      </div>
    </div>
    <div className="appearance-settings-group">
      <label className="appearance-settings-heading" htmlFor="appearance-font">Font Family</label>
      <UiSelect
        className="desktop-select"
        aria-label="Font Family"
        value={font}
        options={FONT_OPTIONS}
        onChange={(nextValue) => {
          const next = nextValue as FontPreference
          appearance.setFont(next)
          void persist({ font: next })
        }}
      />
    </div>
    <div className="appearance-settings-actions"><button type="button" className="desktop-btn" onClick={() => {
        appearance.resetAppearance()
        void persist({ theme: 'system', skin: 'classic', font: 'skin-default', primaryTheme: 'blue', density: 'comfortable', radius: 'medium', shadow: 'soft', animations: true })
      }}>Reset to Default</button>
    </div>
  </section>
}
