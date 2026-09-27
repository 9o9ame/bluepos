import { useState } from 'react'
import { updateAppearance } from '../../api/auth'
import { useAppearance, type InterfaceStyle, type ThemePreference } from './AppearanceProvider'

const THEME_OPTIONS: Array<{ value: ThemePreference; label: string }> = [
  { value: 'system', label: 'System' },
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
]

const SKIN_OPTIONS: Array<{ value: InterfaceStyle; label: string; description: string }> = [
  {
    value: 'classic',
    label: 'Classic',
    description: 'Dense Windows POS style with classic gradients and detailed icons.',
  },
  {
    value: 'hybrid',
    label: 'Hybrid',
    description: 'Classic POS workflow with cleaner surfaces and premium icons.',
  },
  {
    value: 'advanced',
    label: 'Advanced',
    description: 'Modern visual treatment while preserving the same workflow and controls.',
  },
]

export function AppearanceSettings() {
  const { theme, skin, setTheme, setSkin, resetAppearance } = useAppearance()
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  async function persist(nextTheme: ThemePreference, nextSkin: InterfaceStyle) {
    setSaving(true)
    setSaveError(null)

    try {
      await updateAppearance({ theme: nextTheme, skin: nextSkin })
    } catch {
      setSaveError('Could not save appearance preference. Your local selection is still active.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="appearance-settings" aria-label="Appearance settings">
      <div className="appearance-settings-group">
        <div className="appearance-settings-heading">Theme</div>
        <div className="appearance-option-row" role="radiogroup" aria-label="Theme">
          {THEME_OPTIONS.map((option) => (
            <label key={option.value} className="appearance-radio">
              <input
                type="radio"
                name="appearance-theme"
                value={option.value}
                checked={theme === option.value}
                onChange={() => {
                  setTheme(option.value)
                  void persist(option.value, skin)
                }}
              />
              <span>{option.label}</span>
            </label>
          ))}
        </div>
      </div>

      <div className="appearance-settings-group">
        <div className="appearance-settings-heading">Interface Style</div>
        <div className="appearance-skin-grid" role="radiogroup" aria-label="Interface style">
          {SKIN_OPTIONS.map((option) => (
            <label
              key={option.value}
              className={`appearance-skin-card${skin === option.value ? ' is-selected' : ''}`}
            >
              <input
                type="radio"
                name="appearance-skin"
                value={option.value}
                checked={skin === option.value}
                onChange={() => {
                  setSkin(option.value)
                  void persist(theme, option.value)
                }}
              />
              <span className="appearance-skin-title">{option.label}</span>
              <span className="appearance-skin-description">{option.description}</span>
            </label>
          ))}
        </div>
      </div>

      {saveError ? <div className="appearance-save-error">{saveError}</div> : null}
      <div className="appearance-settings-actions">
        <span className="appearance-save-state" aria-live="polite">
          {saving ? 'Saving…' : ''}
        </span>
        <button
          type="button"
          className="desktop-btn"
          disabled={saving}
          onClick={() => {
            resetAppearance()
            void persist('system', 'classic')
          }}
        >
          Reset to Default
        </button>
      </div>
    </section>
  )
}
