import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.tsx'
import { AppearanceProvider } from './features/appearance/AppearanceProvider'
import './index.css'
import './appearance.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppearanceProvider>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </AppearanceProvider>
  </StrictMode>,
)
