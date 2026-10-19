import {
  ChangeDetectionStrategy,
  Component,
  computed,
  signal
} from '@angular/core';

/**
 * Interface defining a specific data point for the sales chart.
 */
interface ChartPoint {
  day: string;
  value: number;
}

/**
 * Interface representing normalized SVG coordinates for rendering.
 */
interface SvgPoint {
  x: number;
  y: number;
}

/**
 * Component that renders a custom SVG-based cubic bezier sales chart.
 */
@Component({
  selector: 'app-sales-chart',
  standalone: true,
  templateUrl: './sales-chart.component.html',
  styleUrl: './sales-chart.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class SalesChartComponent {

  /* ==========================================================
     HEADER DATA
  ========================================================== */

  readonly title = signal('Sales — Last 7 Days');
  readonly branches = signal(4);

  /* ==========================================================
     FOOTER LABELS
  ========================================================== */

  readonly labels = signal([
    'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'
  ]);

  /* ==========================================================
     API DATA
  ========================================================== */

  readonly data = signal<ChartPoint[]>([
    { day: 'Tue', value: 48 },
    { day: 'Wed', value: 60 },
    { day: 'Thu', value: 55 },
    { day: 'Fri', value: 74 },
    { day: 'Sat', value: 66 },
    { day: 'Sun', value: 82 }
  ]);

  /* ==========================================================
     SVG CONFIGURATION
  ========================================================== */

  private readonly svgWidth = 300;
  private readonly svgHeight = 90;

  readonly horizontalPadding = signal(18);
  readonly topPadding = signal(12);
  readonly bottomPadding = signal(12);
  readonly chartMin = signal(35);
  readonly chartMax = signal(85);

  readonly drawableWidth = computed(() =>
    this.svgWidth - this.horizontalPadding() * 2
  );

  readonly drawableHeight = computed(() =>
    this.svgHeight - this.topPadding() - this.bottomPadding()
  );

  readonly dayStep = computed(() =>
    this.drawableWidth() / (this.labels().length - 1)
  );

  /* ==========================================================
     NORMALIZE
  ========================================================== */

  private normalize(value: number): number {
    return (
      this.topPadding() +
      ((this.chartMax() - value) / (this.chartMax() - this.chartMin())) *
      this.drawableHeight()
    );
  }

  /* ==========================================================
     SVG POINTS
  ========================================================== */

  readonly svgPoints = computed<SvgPoint[]>(() => {
    return this.data().map((item: ChartPoint) => {
      const dayIndex = this.labels().indexOf(item.day);
      return {
        x: this.horizontalPadding() + dayIndex * this.dayStep(),
        y: this.normalize(item.value)
      };
    });
  });

  /* ==========================================================
     CUBIC BEZIER LINE
  ========================================================== */

  readonly linePath = computed(() => {
    const points = this.svgPoints();
    if (!points.length) return '';

    let path = `M ${points[0].x} ${points[0].y}`;
    for (let i = 0; i < points.length - 1; i++) {
      const current = points[i];
      const next = points[i + 1];
      const controlX = (current.x + next.x) / 2;
      path += ` C ${controlX} ${current.y}, ${controlX} ${next.y}, ${next.x} ${next.y}`;
    }
    return path;
  });

  /* ==========================================================
     AREA PATH
  ========================================================== */

  readonly areaPath = computed(() => {
    const points = this.svgPoints();
    if (!points.length) return '';

    let path = `M ${points[0].x} ${points[0].y}`;
    for (let i = 0; i < points.length - 1; i++) {
      const current = points[i];
      const next = points[i + 1];
      const controlX = (current.x + next.x) / 2;
      path += ` C ${controlX} ${current.y}, ${controlX} ${next.y}, ${next.x} ${next.y}`;
    }

    path += ` L ${points[points.length - 1].x} ${this.svgHeight} L ${points[0].x} ${this.svgHeight} Z`;
    return path;
  });
}