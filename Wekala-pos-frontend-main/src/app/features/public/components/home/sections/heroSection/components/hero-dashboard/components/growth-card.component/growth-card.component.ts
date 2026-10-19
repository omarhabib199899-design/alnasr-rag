import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

/**
 * Component representing a small growth indicator card, 
 * typically used to highlight metrics within the hero dashboard.
 */
@Component({
  selector: 'app-growth-card',
  standalone: true,
  imports: [],
  templateUrl: './growth-card.component.html',
  styleUrl: './growth-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class GrowthCardComponent {

  // Title of the metric
  readonly title = signal('Growth');

  // Percentage growth value
  readonly value = signal('+18.2%');

  // Timeframe indicator (e.g., Month over Month)
  readonly subtitle = signal('MoM');

}