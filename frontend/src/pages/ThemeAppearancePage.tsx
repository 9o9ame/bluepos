import { Palette } from 'lucide-react'
import { AppearanceSettings } from '../features/appearance/AppearanceSettings'

export function ThemeAppearancePage() {
  return (
    <div className="desktop-page">
      <div className="desktop-page-header">
        <div>
          <h1><Palette size={20} aria-hidden /> Theme &amp; Appearance</h1>
          <p>Personalize how BluePOS looks for your user account.</p>
        </div>
      </div>
      <div className="desktop-card">
        <AppearanceSettings />
      </div>
    </div>
  )
}
