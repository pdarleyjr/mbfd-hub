import React, { useEffect, useRef, useCallback, useState } from 'react';
import { Stage, Layer, Rect, Line, Group, Text, Image } from 'react-konva';
import { useLayoutStore, initializeStore } from '../stores/useLayoutStore';
import { ToolPalette } from './ToolPalette';
import { saveDraft, loadDraft } from '../lib/offlineDb';
import { exportLayoutPdf } from '../lib/pdfExport';
import { reclassifyCompartment } from '../lib/fitLogic';
import type { ApparatusSide, Compartment, Equipment, EquipmentPlacement, FitClassification } from '../types';
import { PIERCE_ASCENDANT_COMPARTMENTS } from '../types';

// ===== Constants =====

const CANVAS_WIDTH = 1200;
const CANVAS_HEIGHT = 800;
const COMPARTMENT_PADDING = 20;
const SCALE_FACTOR = 8; // inches to pixels
const TOOL_SCALE = 20; // pixels per inch (from manifest)

// ===== Side View Dimensions =====

const SIDE_VIEWS: Record<ApparatusSide, { x: number; y: number; width: number; height: number }> = {
  driver: { x: 50, y: 100, width: 1100, height: 600 },
  officer: { x: 50, y: 100, width: 1100, height: 600 },
  rear: { x: 200, y: 100, width: 800, height: 600 },
  top: { x: 100, y: 100, width: 1000, height: 600 },
  cab: { x: 300, y: 150, width: 600, height: 500 },
};

// ===== Fit Classification Colors =====

const FIT_COLORS: Record<FitClassification, string> = {
  fit: '#22c55e',
  tight: '#f59e0b',
  fail: '#ef4444',
  unknown: '#64748b',
};

// ===== Compartment Layout Calculator =====

function calculateCompartmentLayouts(
  compartments: Compartment[],
  side: ApparatusSide
): Map<string, { x: number; y: number; width: number; height: number }> {
  const layouts = new Map();
  const sideCompartments = compartments.filter((c) => c.side === side);
  
  if (sideCompartments.length === 0) return layouts;

  const viewArea = SIDE_VIEWS[side];
  let currentX = viewArea.x + COMPARTMENT_PADDING;
  let currentY = viewArea.y + COMPARTMENT_PADDING;
  let maxWidth = 0;

  sideCompartments.forEach((compartment, index) => {
    const width = compartment.dimensions.width * SCALE_FACTOR;
    const height = compartment.dimensions.height * SCALE_FACTOR;

    // Check if we need to wrap to next row
    if (currentX + width > viewArea.x + viewArea.width - COMPARTMENT_PADDING) {
      currentX = viewArea.x + COMPARTMENT_PADDING;
      currentY += maxWidth + COMPARTMENT_PADDING;
      maxWidth = 0;
    }

    layouts.set(compartment.id, {
      x: currentX,
      y: currentY,
      width,
      height,
    });

    maxWidth = Math.max(maxWidth, height);
    currentX += width + COMPARTMENT_PADDING;
  });

  return layouts;
}

// ===== Equipment Icon Component =====

interface EquipmentIconProps {
  placement: EquipmentPlacement;
  equipment: Equipment;
  layout: { x: number; y: number; width: number; height: number };
  isSelected: boolean;
  onClick: () => void;
  onDragEnd: (x: number, y: number) => void;
}

const EquipmentIcon: React.FC<EquipmentIconProps> = ({
  placement,
  equipment,
  layout,
  isSelected,
  onClick,
  onDragEnd,
}) => {
  const [image, setImage] = useState<HTMLImageElement | null>(null);
  
  // Load equipment icon
  useEffect(() => {
    if (equipment.iconPath) {
      const img = new window.Image();
      img.src = equipment.iconPath;
      img.onload = () => setImage(img);
    }
  }, [equipment.iconPath]);

  const width = equipment.dimensions.length * (TOOL_SCALE / SCALE_FACTOR / 2);
  const height = equipment.dimensions.width * (TOOL_SCALE / SCALE_FACTOR / 2);
  const fitColor = FIT_COLORS[placement.fitClassification] || FIT_COLORS.unknown;

  return (
    <Group
      x={layout.x + placement.position.x}
      y={layout.y + placement.position.y}
      onClick={onClick}
      draggable
      onDragEnd={(e) => {
        const node = e.target;
        // Pass position relative to compartment layout origin
        onDragEnd(node.x() - layout.x, node.y() - layout.y);
      }}
    >
      {image ? (
        <Image
          image={image}
          width={width}
          height={height}
          offsetX={width / 2}
          offsetY={height / 2}
        />
      ) : (
        <Rect
          width={width}
          height={height}
          fill={isSelected ? '#3b82f6' : fitColor}
          stroke={isSelected ? '#60a5fa' : fitColor}
          strokeWidth={isSelected ? 2 : 1}
          cornerRadius={2}
          offsetX={width / 2}
          offsetY={height / 2}
        />
      )}
      {/* Fit indicator ring */}
      <Rect
        width={width + 6}
        height={height + 6}
        stroke={fitColor}
        strokeWidth={isSelected ? 3 : 1.5}
        dash={isSelected ? [4, 2] : []}
        offsetX={(width + 6) / 2}
        offsetY={(height + 6) / 2}
        opacity={0.7}
      />
    </Group>
  );
};

// ===== Compartment Component =====

interface CompartmentShapeProps {
  compartment: Compartment;
  layout: { x: number; y: number; width: number; height: number };
  isSelected: boolean;
  isDropTarget: boolean;
  onClick: () => void;
  onDrop: (equipment: Equipment) => void;
}

const CompartmentShape: React.FC<CompartmentShapeProps> = ({
  compartment,
  layout,
  isSelected,
  isDropTarget,
  onClick,
}) => {
  const { x, y, width, height } = layout;
  const shelfHeight = height / Math.max(compartment.shelfCount, 1);

  // Determine shelf color based on type
  const getShelfColor = () => {
    switch (compartment.shelfType) {
      case 'pull-out':
        return '#3b82f6'; // blue
      case 'assisted':
        return '#06b6d4'; // cyan
      case 'split':
        return '#8b5cf6'; // purple
      default:
        return '#ef4444'; // red for fixed
    }
  };

  return (
    <Group onClick={onClick}>
      {/* Main compartment rectangle */}
      <Rect
        x={x}
        y={y}
        width={width}
        height={height}
        fill={isSelected ? '#1e3a5f' : isDropTarget ? '#14532d' : '#1e293b'}
        stroke={isSelected ? '#3b82f6' : isDropTarget ? '#22c55e' : '#475569'}
        strokeWidth={isSelected ? 3 : isDropTarget ? 3 : 2}
        cornerRadius={4}
      />
      
      {/* Compartment label */}
      <Text
        x={x + 8}
        y={y + 8}
        text={compartment.label}
        fontSize={14}
        fontFamily="Inter, system-ui, sans-serif"
        fill="#94a3b8"
      />
      
      {/* Dimensions */}
      <Text
        x={x + 8}
        y={y + 26}
        text={`${compartment.dimensions.width}" × ${compartment.dimensions.height}"`}
        fontSize={10}
        fontFamily="Inter, system-ui, sans-serif"
        fill="#64748b"
      />
      
      {/* Shelf lines */}
      {compartment.shelfCount > 0 && Array.from({ length: compartment.shelfCount }).map((_, i) => (
        <Line
          key={`shelf-${i}`}
          points={[x + 4, y + shelfHeight * (i + 1), x + width - 4, y + shelfHeight * (i + 1)]}
          stroke={getShelfColor()}
          strokeWidth={2}
          dash={compartment.shelfType === 'fixed' ? [] : [8, 4]}
        />
      ))}
      
      {/* Pegboard indicator */}
      {compartment.hasPegboard && (
        <Group>
          <Rect
            x={x + width - 20}
            y={y + height - 20}
            width={16}
            height={16}
            fill="#f59e0b"
            cornerRadius={2}
          />
          <Text
            x={x + width - 18}
            y={y + height - 18}
            text="P"
            fontSize={10}
            fontFamily="Inter, system-ui, sans-serif"
            fill="#1e293b"
            fontStyle="bold"
          />
        </Group>
      )}
    </Group>
  );
};

// ===== Main Layout Planner Component =====

const LayoutPlanner: React.FC = () => {
  const stageRef = useRef<any>(null);
  const [draggedEquipment, setDraggedEquipment] = useState<Equipment | null>(null);
  const [dropTargetId, setDropTargetId] = useState<string | null>(null);
  const autoSaveTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  
  // Initialize store on mount
  useEffect(() => {
    initializeStore();
  }, []);

  // Get store state and actions
  const {
    currentApparatusId,
    currentApparatusName,
    currentSide,
    zoom,
    compartments,
    equipmentCatalog,
    placements,
    selectedCompartmentId,
    selectedPlacementId,
    hasUnsavedChanges,
    lastAutoSave,
    setSide,
    setZoom,
    selectCompartment,
    selectPlacement,
    placeEquipment,
    movePlacement,
    updatePlacement,
    triggerAutoSave,
  } = useLayoutStore();

  // ===== Dexie Autosave — every 5 seconds =====
  useEffect(() => {
    autoSaveTimerRef.current = setInterval(() => {
      const state = useLayoutStore.getState();
      if (state.hasUnsavedChanges && state.placements.length > 0 && state.currentApparatusId) {
        saveDraft(state.currentApparatusId, state.placements).catch(console.error);
        state.triggerAutoSave();
      }
    }, 5000);

    return () => {
      if (autoSaveTimerRef.current) clearInterval(autoSaveTimerRef.current);
    };
  }, []);

  // Restore draft on mount
  useEffect(() => {
    if (currentApparatusId) {
      loadDraft(currentApparatusId).then((draft) => {
        if (draft && draft.placements.length > 0) {
          // Only restore if we have no placements yet
          const state = useLayoutStore.getState();
          if (state.placements.length === 0) {
            draft.placements.forEach((p) => {
              placeEquipment({ ...p });
            });
          }
        }
      }).catch(console.error);
    }
  }, [currentApparatusId]);

  // ===== PDF Export Handler =====
  const handleExportPdf = useCallback(() => {
    const stage = stageRef.current;
    if (!stage) return;
    exportLayoutPdf(
      stage,
      currentApparatusName || 'Apparatus',
      currentSide.charAt(0).toUpperCase() + currentSide.slice(1),
    ).catch(console.error);
  }, [currentApparatusName, currentSide]);

  // Calculate compartment layouts for current side
  const compartmentLayouts = calculateCompartmentLayouts(compartments, currentSide);
  const sideCompartments = compartments.filter((c) => c.side === currentSide);

  // Handle wheel zoom
  const handleWheel = useCallback((e: any) => {
    e.evt.preventDefault();
    const scaleBy = 1.1;
    const stage = stageRef.current;
    if (!stage) return;

    const oldScale = zoom;
    const pointer = stage.getPointerPosition();
    if (!pointer) return;

    const mousePointTo = {
      x: (pointer.x - stage.x()) / oldScale,
      y: (pointer.y - stage.y()) / oldScale,
    };

    const direction = e.evt.deltaY > 0 ? -1 : 1;
    const newScale = direction > 0 ? oldScale * scaleBy : oldScale / scaleBy;
    const clampedScale = Math.max(0.25, Math.min(newScale, 3));

    setZoom(clampedScale);
  }, [zoom, setZoom]);

  // Handle compartment click
  const handleCompartmentClick = useCallback((compartmentId: string) => {
    selectCompartment(compartmentId);
  }, [selectCompartment]);

  // Handle drag start from ToolPalette
  const handleDragStart = useCallback((equipment: Equipment) => {
    setDraggedEquipment(equipment);
  }, []);

  // Handle drag end
  const handleDragEnd = useCallback(() => {
    setDraggedEquipment(null);
    setDropTargetId(null);
  }, []);

  // Handle drop on compartment
  const handleDrop = useCallback((compartmentId: string, equipment: Equipment) => {
    const compartment = compartments.find(c => c.id === compartmentId);
    if (!compartment) return;

    // Calculate center position within compartment
    const layout = compartmentLayouts.get(compartmentId);
    if (!layout) return;

    const position = {
      x: layout.width / 2,
      y: layout.height / 2,
      rotation: 0,
    };

    const placement: Omit<EquipmentPlacement, 'id'> = {
      equipmentId: equipment.id,
      compartmentId,
      shelfIndex: 0,
      position,
      fitClassification: 'unknown',
    };

    placeEquipment(placement);
    setDraggedEquipment(null);
    setDropTargetId(null);
  }, [compartments, compartmentLayouts, placeEquipment]);

  // Handle equipment move — with fit reclassification
  const handleEquipmentMove = useCallback((placementId: string, x: number, y: number, rotation: number = 0) => {
    movePlacement(placementId, { x, y, rotation });

    // Reclassify fit for the affected compartment
    const state = useLayoutStore.getState();
    const placement = state.placements.find((p) => p.id === placementId);
    if (placement) {
      const fits = reclassifyCompartment(
        placement.compartmentId,
        state.compartments,
        state.placements,
        state.equipmentCatalog,
        SCALE_FACTOR,
      );
      fits.forEach((fitClass, pId) => {
        updatePlacement(pId, { fitClassification: fitClass });
      });
    }
  }, [movePlacement, updatePlacement]);

  // Side selector buttons
  const sides: { id: ApparatusSide; label: string }[] = [
    { id: 'driver', label: 'Driver Side' },
    { id: 'officer', label: 'Officer Side' },
    { id: 'rear', label: 'Rear' },
    { id: 'top', label: 'Top' },
    { id: 'cab', label: 'Cab' },
  ];

  // Get equipment by ID
  const getEquipmentById = useCallback((id: string) => {
    return equipmentCatalog.find(e => e.id === id);
  }, [equipmentCatalog]);

  // Get placements for current side
  const sidePlacements = placements.filter(p => {
    const compartment = compartments.find(c => c.id === p.compartmentId);
    return compartment?.side === currentSide;
  });

  return (
    <div className="layout-planner-bg" style={{ display: 'flex', minHeight: '100vh' }}>
      {/* Home Button */}
      <a href="/" className="layout-home-btn" aria-label="Return to Home">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M3 12l9-9 9 9"></path>
          <path d="M9 21V9h6v12"></path>
        </svg>
        Home
      </a>

      {/* PDF Export Button */}
      <button className="layout-export-btn" aria-label="Export Layout as PDF" onClick={handleExportPdf}>
        <svg
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
          <polyline points="7 10 12 15 17 10" />
          <line x1="12" y1="15" x2="12" y2="3" />
        </svg>
        Export PDF
      </button>

      {/* Header */}
      <header style={{
        position: 'fixed',
        top: 'max(12px, env(safe-area-inset-top))',
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 100,
        display: 'flex',
        alignItems: 'center',
        gap: '16px',
        padding: '8px 16px',
        background: 'rgba(15, 23, 42, 0.95)',
        borderRadius: '8px',
        boxShadow: '0 4px 12px rgba(0, 0, 0, 0.3)',
      }}>
        <h1 style={{
          margin: 0,
          fontSize: '16px',
          fontWeight: 600,
          color: '#e2e8f0',
          fontFamily: 'Inter, system-ui, sans-serif',
        }}>
          {currentApparatusName || 'Apparatus Layout Planner'}
        </h1>
      </header>

      {/* Side Selector */}
      <nav style={{
        position: 'fixed',
        top: 'max(60px, calc(12px + env(safe-area-inset-top) + 44px))',
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 90,
        display: 'flex',
        gap: '4px',
        padding: '4px',
        background: 'rgba(15, 23, 42, 0.95)',
        borderRadius: '8px',
      }}>
        {sides.map((side) => (
          <button
            key={side.id}
            onClick={() => setSide(side.id)}
            className={`side-btn ${currentSide === side.id ? 'side-btn-active' : 'side-btn-inactive'}`}
          >
            {side.label}
          </button>
        ))}
      </nav>

      {/* Main Content Area */}
      <div style={{
        flex: 1,
        display: 'flex',
        paddingTop: 'calc(env(safe-area-inset-top, 0px) + 120px)',
        paddingBottom: 'env(safe-area-inset-bottom, 0px)',
      }}>
        {/* Canvas Container */}
        <div style={{
          flex: 1,
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'center',
          padding: '16px',
        }}>
          <div 
            className="canvas-container" 
            style={{ width: '100%', height: '100%', maxWidth: '1400px' }}
            onDragOver={(e) => {
              e.preventDefault();
              e.dataTransfer.dropEffect = 'copy';
            }}
            onDrop={(e) => {
              e.preventDefault();
              if (draggedEquipment && dropTargetId) {
                handleDrop(dropTargetId, draggedEquipment);
              }
            }}
          >
            <Stage
              ref={stageRef}
              width={CANVAS_WIDTH}
              height={CANVAS_HEIGHT}
              scaleX={zoom}
              scaleY={zoom}
              onWheel={handleWheel}
              style={{ background: '#1a1a2e' }}
            >
              <Layer>
                {/* Background */}
                <Rect
                  x={0}
                  y={0}
                  width={CANVAS_WIDTH}
                  height={CANVAS_HEIGHT}
                  fill="#1a1a2e"
                />
                
                {/* View area outline */}
                <Rect
                  x={SIDE_VIEWS[currentSide].x}
                  y={SIDE_VIEWS[currentSide].y}
                  width={SIDE_VIEWS[currentSide].width}
                  height={SIDE_VIEWS[currentSide].height}
                  fill="#0f172a"
                  stroke="#334155"
                  strokeWidth={1}
                  cornerRadius={4}
                />
                
                {/* Compartments */}
                {sideCompartments.map((compartment) => {
                  const layout = compartmentLayouts.get(compartment.id);
                  if (!layout) return null;
                  
                  return (
                    <CompartmentShape
                      key={compartment.id}
                      compartment={compartment}
                      layout={layout}
                      isSelected={selectedCompartmentId === compartment.id}
                      isDropTarget={dropTargetId === compartment.id}
                      onClick={() => handleCompartmentClick(compartment.id)}
                      onDrop={(equipment) => handleDrop(compartment.id, equipment)}
                    />
                  );
                })}
                
                {/* Equipment Placements */}
                {sidePlacements.map((placement) => {
                  const equipment = getEquipmentById(placement.equipmentId);
                  const layout = compartmentLayouts.get(placement.compartmentId);
                  if (!equipment || !layout) return null;
                  
                  return (
                    <EquipmentIcon
                      key={placement.id}
                      placement={placement}
                      equipment={equipment}
                      layout={layout}
                      isSelected={selectedPlacementId === placement.id}
                      onClick={() => selectPlacement(placement.id)}
                      onDragEnd={(x, y) => handleEquipmentMove(placement.id, x, y)}
                    />
                  );
                })}
              </Layer>
            </Stage>
          </div>
        </div>

        {/* Tool Palette */}
        <ToolPalette
          onDragStart={handleDragStart}
          onDragEnd={handleDragEnd}
        />
      </div>

      {/* Status Bar */}
      <div className="status-bar" style={{
        position: 'fixed',
        bottom: 'env(safe-area-inset-bottom, 0px)',
        left: 'env(safe-area-inset-left, 0px)',
        right: 'env(safe-area-inset-right, 0px)',
      }}>
        <span>Side: {currentSide.charAt(0).toUpperCase() + currentSide.slice(1)}</span>
        <span>Zoom: {Math.round(zoom * 100)}%</span>
        <span>Compartments: {sideCompartments.length}</span>
        <span>Equipment: {sidePlacements.length}</span>
      </div>
    </div>
  );
};

export default LayoutPlanner;