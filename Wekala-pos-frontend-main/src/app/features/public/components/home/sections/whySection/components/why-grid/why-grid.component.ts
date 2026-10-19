import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { FeatureCardComponent } from '../../../../../../../../shared/components/cards/feature-card/feature-card.component';

/**
 * Interface defining the structure of a core value proposition item.
 */
interface WhyItem {
  title: string;
  icon: string;
}

/**
 * Component displaying a grid of key benefits that differentiate Wekala POS.
 */
@Component({
  selector: 'app-why-grid',
  standalone: true,
  imports: [
    FeatureCardComponent
  ],
  templateUrl: './why-grid.component.html',
  styleUrl: './why-grid.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class WhyGridComponent {

  // Collection of key selling points for the Wekala POS platform
  readonly items = signal<WhyItem[]>([
    {
      title: 'Simple, easy-to-use interface',
      icon: 'bolt'
    },
    {
      title: 'Suitable for different industries',
      icon: 'layer-group'
    },
    {
      title: 'Fast sales & checkout experience',
      icon: 'arrow-trend-up'
    },
    {
      title: 'Centralized business management',
      icon: 'building'
    },
    {
      title: 'Multi-branch support',
      icon: 'location'
    },
    {
      title: 'Real-time sales & inventory tracking',
      icon: 'rotate'
    },
    {
      title: 'Flexible roles & permissions',
      icon: 'shield-check'
    },
    {
      title: 'Clear business reports',
      icon: 'chart-column'
    },
    {
      title: 'Responsive across devices',
      icon: 'credit'
    },
    {
      title: 'Scales with your growth',
      icon: 'sparkle'
    },
    {
      title: 'Professional technical support',
      icon: 'headset'
    },
    {
      title: 'Customizable to your needs',
      icon: 'sliders'
    }
  ]);

}