import { useState, useEffect, useCallback } from 'react';
import { useLayoutStore } from '../stores/useLayoutStore';
import type { Equipment, ApparatusSide } from '../types';

// Scale: 20 pixels per inch (from manifest)
const SCALE = 20;

interface ToolPaletteProps {
  onDragStart?: (equipment: Equipment) => void;
  onDragEnd?: () => void;
}

// Category display names and colors
const CATEGORY_CONFIG: Record<string, { name: string; color: string; icon: string }> = {
  'forcible-entry': { name: 'Forcible Entry', color: '#ef4444', icon: '🪓' },
  'ventilation': { name: 'Ventilation', color: '#f59e0b', icon: '🌀' },
  'rescue': { name: 'Rescue', color: '#22c55e', icon: '🚑' },
  'hose': { name: 'Hose & Appliances', color: '#3b82f6', icon: '💧' },
  'medical': { name: 'Medical', color: '#ec4899', icon: '⚕️' },
  'hand-tools': { name: 'Hand Tools', color: '#8b5cf6', icon: '🔧' },
  'power-tools': { name: 'Power Tools', color: '#f97316', icon: '⚡' },
  'ladders': { name: 'Ladders', color: '#14b8a6', icon: '🪜' },
};

export function ToolPalette({ onDragStart, onDragEnd }: ToolPaletteProps) {
  const { equipmentCatalog, addEquipment } = useLayoutStore();
  const [categories, setCategories] = useState<Array<{ id: string; name: string; priority: number }>>([]);
  const [expandedCategories, setExpandedCategories] = useState<Set<string>>(new Set(['forcible-entry']));
  const [searchQuery, setSearchQuery] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [draggedItem, setDraggedItem] = useState<Equipment | null>(null);

  // Load tool manifest
  useEffect(() => {
    async function loadManifest() {
      try {
        const response = await fetch('/data/tool-manifest.json');
        if (!response.ok) throw new Error('Failed to load tool manifest');
        
        const manifest = await response.json();
        
        // Set categories
        setCategories(manifest.categories.sort((a: { priority: number }, b: { priority: number }) => a.priority - b.priority));
        
        // Populate equipment catalog if empty
        if (equipmentCatalog.length === 0) {
          manifest.equipment.forEach((item: Equipment) => {
            addEquipment(item);
          });
        }
        
        setIsLoading(false);
      } catch (error) {
        console.error('Error loading tool manifest:', error);
        setIsLoading(false);
      }
    }
    
    loadManifest();
  }, []);

  // Filter equipment by search
  const filteredEquipment = useCallback((categoryId: string) => {
    const equipment = equipmentCatalog.filter(e => e.category === categoryId);
    if (!searchQuery) return equipment;
    
    const query = searchQuery.toLowerCase();
    return equipment.filter(e => 
      e.name.toLowerCase().includes(query) ||
      e.id.toLowerCase().includes(query)
    );
  }, [equipmentCatalog, searchQuery]);

  // Toggle category expansion
  const toggleCategory = (categoryId: string) => {
    setExpandedCategories(prev => {
      const next = new Set(prev);
      if (next.has(categoryId)) {
        next.delete(categoryId);
      } else {
        next.add(categoryId);
      }
      return next;
    });
  };

  // Handle drag start
  const handleDragStart = (e: React.DragEvent, equipment: Equipment) => {
    setDraggedItem(equipment);
    e.dataTransfer.setData('application/json', JSON.stringify(equipment));
    e.dataTransfer.effectAllowed = 'copy';
    
    // Create drag image
    const dragPreview = document.createElement('div');
    dragPreview.className = 'drag-preview';
    dragPreview.style.cssText = `
      width: ${equipment.dimensions.width * SCALE}px;
      height: ${equipment.dimensions.height * SCALE}px;
      background: rgba(59, 130, 246, 0.3);
      border: 2px solid #3b82f6;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      color: white;
      padding: 4px;
    `;
    dragPreview.textContent = equipment.name;
    document.body.appendChild(dragPreview);
    e.dataTransfer.setDragImage(dragPreview, equipment.dimensions.width * SCALE / 2, equipment.dimensions.height * SCALE / 2);
    
    setTimeout(() => document.body.removeChild(dragPreview), 0);
    
    onDragStart?.(equipment);
  };

  // Handle drag end
  const handleDragEnd = () => {
    setDraggedItem(null);
    onDragEnd?.();
  };

  if (isLoading) {
    return (
      <div className="equipment-library" style={{ width: '280px', padding: '16px' }}>
        <div style={{ color: '#94a3b8', textAlign: 'center' }}>Loading equipment...</div>
      </div>
    );
  }

  return (
    <div className="equipment-library" style={{ width: '280px', padding: '16px' }}>
      {/* Header */}
      <div style={{ marginBottom: '16px' }}>
        <h2 style={{ 
          fontSize: '16px', 
          fontWeight: 600, 
          color: '#e2e8f0', 
          margin: 0,
          marginBottom: '12px'
        }}>
          Equipment Library
        </h2>
        
        {/* Search */}
        <input
          type="text"
          placeholder="Search equipment..."
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          style={{
            width: '100%',
            padding: '8px 12px',
            borderRadius: '6px',
            border: '1px solid #334155',
            background: '#0f172a',
            color: '#e2e8f0',
            fontSize: '13px',
            outline: 'none',
          }}
        />
      </div>

      {/* Categories */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
        {categories.map(category => {
          const config = CATEGORY_CONFIG[category.id] || { name: category.name, color: '#6b7280', icon: '📦' };
          const equipment = filteredEquipment(category.id);
          const isExpanded = expandedCategories.has(category.id);
          
          if (searchQuery && equipment.length === 0) return null;
          
          return (
            <div key={category.id}>
              {/* Category Header */}
              <button
                onClick={() => toggleCategory(category.id)}
                style={{
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                  padding: '10px 12px',
                  background: isExpanded ? '#334155' : '#1e293b',
                  border: '1px solid #334155',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  color: '#e2e8f0',
                  fontSize: '13px',
                  fontWeight: 500,
                  transition: 'all 0.2s',
                }}
              >
                <span style={{ fontSize: '16px' }}>{config.icon}</span>
                <span style={{ flex: 1 }}>{config.name}</span>
                <span style={{ 
                  background: config.color, 
                  color: 'white', 
                  fontSize: '11px', 
                  padding: '2px 6px', 
                  borderRadius: '10px' 
                }}>
                  {equipment.length}
                </span>
                <svg 
                  style={{ 
                    transform: isExpanded ? 'rotate(180deg)' : 'rotate(0deg)',
                    transition: 'transform 0.2s'
                  }}
                  width="16" 
                  height="16" 
                  viewBox="0 0 24 24" 
                  fill="none" 
                  stroke="currentColor" 
                  strokeWidth="2"
                >
                  <path d="M6 9l6 6 6-6" />
                </svg>
              </button>
              
              {/* Equipment List */}
              {isExpanded && (
                <div style={{ 
                  marginTop: '4px', 
                  display: 'flex', 
                  flexDirection: 'column', 
                  gap: '4px' 
                }}>
                  {equipment.map(item => (
                    <div
                      key={item.id}
                      draggable
                      onDragStart={(e) => handleDragStart(e, item)}
                      onDragEnd={handleDragEnd}
                      className="equipment-card"
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '10px',
                        padding: '10px',
                        background: draggedItem?.id === item.id ? '#1e3a5f' : '#1e293b',
                        border: `1px solid ${draggedItem?.id === item.id ? '#3b82f6' : '#334155'}`,
                        borderRadius: '6px',
                        cursor: 'grab',
                        opacity: draggedItem?.id === item.id ? 0.7 : 1,
                        transition: 'all 0.2s',
                      }}
                    >
                      {/* Icon */}
                      <div style={{
                        width: '40px',
                        height: '40px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        background: '#0f172a',
                        borderRadius: '4px',
                        flexShrink: 0,
                        overflow: 'hidden',
                      }}>
                        {item.iconPath ? (
                          <img 
                            src={item.iconPath} 
                            alt={item.name}
                            style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }}
                            onError={(e) => {
                              (e.target as HTMLImageElement).style.display = 'none';
                            }}
                          />
                        ) : (
                          <span style={{ fontSize: '20px' }}>📦</span>
                        )}
                      </div>
                      
                      {/* Info */}
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ 
                          fontSize: '12px', 
                          fontWeight: 500, 
                          color: '#e2e8f0',
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                        }}>
                          {item.name}
                        </div>
                        <div style={{ 
                          fontSize: '10px', 
                          color: '#94a3b8',
                          marginTop: '2px',
                        }}>
                          {item.dimensions.length}" × {item.dimensions.width}" × {item.dimensions.height}"
                          {item.dimensions.weight && ` • ${item.dimensions.weight}lb`}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>
      
      {/* Footer */}
      <div style={{ 
        marginTop: '16px', 
        paddingTop: '12px', 
        borderTop: '1px solid #334155',
        fontSize: '11px',
        color: '#64748b',
        textAlign: 'center',
      }}>
        Drag items to compartments
      </div>
    </div>
  );
}