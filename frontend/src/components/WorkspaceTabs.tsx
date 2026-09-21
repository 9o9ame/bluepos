type WorkspaceTab = {
  id: string
  title: string
}

type WorkspaceTabsProps = {
  tabs: WorkspaceTab[]
  activeId: string
  onSelect: (id: string) => void
}

export function WorkspaceTabs({ tabs, activeId, onSelect }: WorkspaceTabsProps) {
  return (
    <div className="flex items-center gap-1 border-b border-slate-400 bg-slate-700 px-2 py-1">
      {tabs.map((tab) => {
        const selected = tab.id === activeId
        return (
          <button
            key={tab.id}
            type="button"
            className={`rounded-sm px-3 py-0.5 text-[12px] ${
              selected ? 'bg-white text-slate-900' : 'bg-slate-600 text-white hover:bg-slate-500'
            }`}
            onClick={() => onSelect(tab.id)}
          >
            {tab.title}
          </button>
        )
      })}
    </div>
  )
}
