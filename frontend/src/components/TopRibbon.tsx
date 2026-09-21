const RIBBON_TABS = ['Definition', 'Daily Entries', 'Reports', 'Tools', 'Administration', 'Help'] as const

export type RibbonTab = (typeof RIBBON_TABS)[number]

type TopRibbonProps = {
  activeTab: RibbonTab
  onChange: (tab: RibbonTab) => void
}

export function TopRibbon({ activeTab, onChange }: TopRibbonProps) {
  return (
    <div className="border-b border-slate-400 bg-gradient-to-b from-slate-100 to-slate-200">
      <div className="flex items-end gap-1 px-2 pt-1" role="tablist" aria-label="Main ribbon">
        {RIBBON_TABS.map((tab) => {
          const selected = tab === activeTab
          return (
            <button
              key={tab}
              type="button"
              role="tab"
              aria-selected={selected}
              className={`rounded-t px-3 py-1 text-[12px] font-semibold tracking-wide ${
                selected
                  ? 'border border-b-0 border-slate-400 bg-white text-slate-900'
                  : 'border border-transparent text-slate-600 hover:bg-white/70'
              }`}
              onClick={() => onChange(tab)}
            >
              {tab}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export { RIBBON_TABS }
