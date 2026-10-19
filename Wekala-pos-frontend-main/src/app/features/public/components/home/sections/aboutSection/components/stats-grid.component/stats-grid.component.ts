import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { FeatureCardComponent } from '../../../../../../../../shared/components/cards/feature-card/feature-card.component';

/**
 * Interface representing a statistical highlight or system metric.
 */
interface StatItem {
  value: string;
  title: string;
}

@Component({
  selector: 'app-stats-grid',
  standalone: true,
  imports: [
    FeatureCardComponent
  ],
  templateUrl: './stats-grid.component.html',
  styleUrl: './stats-grid.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class StatsGridComponent {

  // List of key performance metrics for the Wekala POS system
  readonly stats = signal<StatItem[]>([
    { value: '10+', title: 'Core Modules' },
    { value: '5+', title: 'Industries' },
    { value: '24/7', title: 'Support' },
    { value: '&infin;', title: 'Branches' }
  ]);
}